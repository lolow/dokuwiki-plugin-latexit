<?php

/**
 * DokuWiki Plugin latexit (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Adam Kučera <adam.kucera@wrent.cz>
 */
// must be run within Dokuwiki
if (!defined('DOKU_INC'))
    die();

/**
 * Syntax component handels all substitutions and new DW commands in original text.
 */
class syntax_plugin_latexit_base extends DokuWiki_Syntax_Plugin {

    /**
     * @return string Syntax mode type
     */
    public function getType() {
        return 'substition';
    }

    /**
     * @return int Sort order - Low numbers go before high numbers
     */
    public function getSort() {
        return 245;
    }

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('~~~*RECURSIVE~*~~', $mode, 'plugin_latexit_base');
    }

    /**
     * Handle matches of the latexit syntax
     *
     * @param string $match The match of the syntax
     * @param int    $state The state of the handler
     * @param int    $pos The position in the document
     * @param Doku_Handler    $handler The handler
     * @return array Data for the renderer
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        if (preg_match('#~~RECURSIVE~~#', $match)) {
            $tildas = explode('RECURSIVE', $match);

            if ($tildas[0] == $tildas[1]) {
                //this will count the level of the header according to number of ~ used
                $level = 7 - strspn($tildas[0], '~');
                if($level > 5) $level = 5;
                if($level < 1) $level = 1;

                return array($state, $level);
            } else {
                //handle header with unequal tags as text
                $handler->_addCall('cdata', array($match), $pos);
                return false;
            }
        }
        return array($state, null);
    }

    /**
     * Render xhtml output or metadata
     *
     * @param string         $mode      Renderer mode (supported modes: xhtml)
     * @param Doku_Renderer  $renderer  The renderer
     * @param array          $data      The data from the handler() function
     * @return bool If rendering was successful.
     */
    public function render($mode, Doku_Renderer $renderer, $data) {
        $level = $data[1];
        if($level === null) return false;

        if($mode == 'xhtml') {
            //inserts the information about set header level to XHMTL
            /** @var Doku_Renderer_xhtml $renderer */

            $renderer->doc .= '<h'.$level.'>'.hsc($this->getConf('link_insertion_message')).'</h'.$level.'>';
            return true;

        } elseif($mode == 'latex') {
            //set the next link to be added recursively

            //there might be more plugins rendering latex and calling this functions could cause an error
            if(method_exists($renderer, '_setRecursive')) {
                /** @var renderer_plugin_latexit $renderer */
                $renderer->_setRecursive(true);
                $renderer->_increaseLevel($level - 1);
            }

            return true;
        }

        return false;
    }
}
