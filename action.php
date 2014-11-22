<?php

/**
 * DokuWiki Plugin latexit (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Adam Kučera <adam.kucera@wrent.cz>
 * @author     Luigi Micco <l.micco@tiscali.it>
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if (!defined('DOKU_INC'))
    die();

/**
 * Action plugin component class handles calling of events before and after
 * some actions.
 */
class action_plugin_latexit extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for given events
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     */
    public function register(Doku_Event_Handler $controller) {
        //call _purgeCache before using parser's cache
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, '_purgeCache');

        $controller->register_hook('TEMPLATE_PAGETOOLS_DISPLAY', 'BEFORE', $this, 'addbutton', array());
    }

    /**
     * Add 'export pdf'-button to pagetools
     *
     * This function is based on dw2pdf plugin.
     * It is not my own work.
     * https://github.com/splitbrain/dokuwiki-plugin-dw2pdf/blob/master/
     * 
     * @param Doku_Event $event
     * @param mixed      $param not defined
     * @author     Luigi Micco <l.micco@tiscali.it>
     * @author     Andreas Gohr <andi@splitbrain.org>
     */
    public function addbutton(Doku_Event $event, $param) {
        global $ID, $REV;

        if ( $this->getConf('showexportbutton') && $event->data['view'] == 'main') {
            $params = array('do' => 'export_latexit');
            if ($REV) {
                $params['rev'] = $REV;
            }

            // insert button at position before last (up to top)
            $event->data['items'] = array_slice($event->data['items'], 0, -1, true) +
                array('export_latexit' =>
                          '<li>'
                          . '<a href=' . wl($ID, $params) . '  class="action export_latexit" rel="nofollow" title="' . $this->getLang('export_latex_button') . '">'
                          . '<span>' . $this->getLang('export_latex_button') . '</span>'
                          . '</a>'
                          . '</li>'
                ) +
                array_slice($event->data['items'], -1, 1, true);
        }
    }

    /**
     * Function purges latexit cache, so even a change in recursively inserted
     * page will generate new file.
     *
     * @param Doku_Event $event Pointer to the give DW event.
     * @param array $param event parameters
     */
    public function _purgeCache(Doku_Event $event, $param) {
        if ($event->data->mode == 'latexit') {
            //touching main config will make all cache invalid
            touch(DOKU_INC . 'conf/local.php');
        }
    }

}
