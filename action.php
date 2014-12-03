<?php

/**
 * DokuWiki Plugin latexit (Action Component)
 *
 * @license    GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author     Adam Kučera <adam.kucera@wrent.cz>
 * @author     Luigi Micco <l.micco@tiscali.it>
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

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

        if($this->getConf('showexportbutton') && $event->data['view'] == 'main') {
            $params = array('do' => 'export_latexit');
            if($REV) {
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
     * @param array      $param event parameters
     */
    public function _purgeCache(Doku_Event $event, $param) {

        /** @var cache_parser $cache */
        $cache = & $event->data;

        if($cache->mode == 'latexit') {
            /** @var cache_renderer $cache */

            // use per header indent level a separated cache file of the rendered latexit page
            /** @var helper_plugin_latexit $store */
            $store = $this->loadHelper('latexit');
            $headerindent = $store->getHeaderIndent();

            if($headerindent > 0) {
                $cache->key .= "#indent" . $headerindent;
                $cache->cache = getCacheName($cache->key, $cache->ext);
            }

            /**
             * @deprecated 3-12-2014 since development version of this date DokuWiki add pageid to the renderer for not
             *                       default exports. In older version this workaround is required.
             */
            $cachepageid = $cache->page;
            if(!$cachepageid) {
                $minimumfilename = wikiFN('');
                $posext = strrpos($minimumfilename, '.');
                $lenext = strlen(substr($minimumfilename, $posext));
                $cachepageid = substr($cache->file, $posext, -$lenext);
                $cachepageid = utf8_decodeFN($cachepageid);
                $cachepageid = str_replace('/', ':', $cachepageid);
            }

            //add included pages to dependencies
            $depends = p_get_metadata($cachepageid, 'plugin_latexit');

            if(!is_array($depends)) return; // nothing to do for us

            if(is_array($depends['insertedpages'])) {
                foreach($depends['insertedpages'] as $pageid => $exists) {
                    if(!$exists) continue;

                    $file = wikiFN($pageid);
                    if(!in_array($file, $cache->depends['files'])) {
                        $cache->depends['files'][] = $file;
                    }
                }
            }
        }
    }

}
