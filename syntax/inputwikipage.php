<?php

/**
 * DokuWiki Plugin latexit (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Adam Kučera <adam.kucera@wrent.cz>
 * @author  Gerrit Uitslag <klapinklapin@gmail.com>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

/**
 * Syntax component handels all substitutions and new DW commands in original text.
 */
class syntax_plugin_latexit_inputwikipage extends DokuWiki_Syntax_Plugin {

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
        $this->Lexer->addSpecialPattern('\\\\inputwikipage(?:\[.*?\])?\{.*?\}', $mode, 'plugin_latexit_inputwikipage');
    }

    /**
     * Handle matches of the latexit syntax:
     *  - \inputwikipage{<pageid>}
     *  - \inputwikipage[<levelnr>]{<pageid>}
     *
     * @param string       $match   The match of the syntax
     * @param int          $state   The state of the handler
     * @param int          $pos     The position in the document
     * @param Doku_Handler $handler The handler
     * @return array Data for the renderer
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        $match = substr($match, 14, -1); // remove \inputwikipage and }
        list($options, $page) = explode('{', $match, 2);

        list($rawpageid, $title) = explode('|', $page);

        if($options) {
            $options = trim(substr($options, 1, -1)); // remove [ ]
        } else {
            $options = 1;
        }
        $level = (int) $options;
        if($level > 5) $level = 5; //<h5>
        if($level < 1) $level = 1; //<h1>

        return array($state, $level, $rawpageid, $title);
    }

    /**
     * Render xhtml output or metadata
     *
     * @param string        $mode     Renderer mode (supported modes: xhtml)
     * @param Doku_Renderer $renderer The renderer
     * @param array         $data     The data from the handler() function
     * @return bool If rendering was successful.
     */
    public function render($mode, Doku_Renderer $renderer, $data) {
        global $ID;
        list( /* $state */, $level, $pageid, $title) = $data;

        if($level === null) return false;

        // indention with respect to parent
        $indent = $level - 1;

        if($mode == 'xhtml') {
            //inserts the information about set header level to XHMTL
            /** @var Doku_Renderer_xhtml $renderer */

            $renderer->doc .= '<h' . $level . '>' . hsc($this->getConf('link_insertion_message')) . '</h' . $level . '>';
            $renderer->internallink($pageid, $title);
            return true;

        } elseif($mode == 'latex') {
            //set the next link to be added recursively

            //there might be more plugins rendering latex and calling this functions could cause an error
            if(method_exists($renderer, 'insertWikipage')) {
                /** @var renderer_plugin_latexit $renderer */

                $renderer->increaseHeaderIndent($indent);

                resolve_pageid(getNS($ID), $pageid, $exists);
                $renderer->insertWikipage($pageid, $exists);

            } else {
                if(!$title) {
                    $title = $pageid;
                }
                $renderer->cdata($title);
            }

            return true;

        } elseif($mode == 'metadata') {
            /**
             * Store data for cache expiring check
             *
             * @see Doku_Renderer_metadata::internallink()
             * @var Doku_Renderer_metadata $renderer
             */

            $parts = explode('?', $pageid, 2);
            if(count($parts) === 2) {
                $pageid = $parts[0];
            }
            $default = $renderer->_simpleTitle($pageid);

            // first resolve and clean up the $id
            resolve_pageid(getNS($ID), $pageid, $exists);
            @list($page) = explode('#', $pageid, 2);

            // default metadata for a link
            $renderer->meta['relation']['references'][$page] = $exists;

            // store as included page, required for latex cache expiring
            $renderer->meta['plugin_latexit']['insertedpages'][$page] = $exists;

            // add link title to summary
            if($renderer->capture) {
                $name = $renderer->_getLinkTitle($title, $default, $pageid);
                $renderer->doc .= $name;
            }
            return true;
        }

        return false;
    }
}
