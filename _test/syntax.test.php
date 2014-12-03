<?php

/**
 * Syntax tests for the latexit plugin
 *
 * @group plugin_latexit
 * @group plugins
 */
class syntax_plugin_latexit_base_test extends DokuWikiTest {

    /**
     * These plugins will be loaded for testing.
     * @var array
     */
    protected $pluginsEnabled = array('latexit', 'mathjax', 'imagereference', 'zotero');
    /**
     * Variable to store the instance of syntax plugin.
     * @var syntax_plugin_latexit_base
     */
    protected $latexit_base;
    /**
     * Variable to store the instance of syntax plugin.
     * @var syntax_plugin_latexit_inputwikipage
     */
    protected $latexit_inputwikipage;

    /**
     * Prepares the testing environment.
     */
    public function setUp() {
        parent::setUp();

        $this->latexit_base = new syntax_plugin_latexit_base();
        $this->latexit_inputwikipage = new syntax_plugin_latexit_inputwikipage();

    }

    /**
     * Testing getType method.
     */
    public function test_getType() {
        $this->assertEquals("substition", $this->latexit_base->getType());
    }

    /**
     * Testing isSingleton method.
     */
    public function test_isSingleton() {
        $this->assertTrue($this->latexit_base->isSingleton());
    }

    /**
     * Testing handle method.
     */
    public function test_handle() {
        //test recursive insertion part of the method
        $result = $this->latexit_base->handle("~~~RECURSIVE~~~", "", 0, new Doku_Handler());
        $expect = array("", 4);
        $this->assertEquals($expect, $result);
    }
    public function test_handle_invalidsyntaxes() {
        //too long, more than 6 ~
        $result = $this->latexit_base->handle("~~~~~~~RECURSIVE~~~~~~~", "", 0, new Doku_Handler());
        $expect = array("", 1);
        $this->assertEquals($expect, $result);

        //too short, only 1 ~
        $result = $this->latexit_base->handle("~RECURSIVE~", "", 0, new Doku_Handler());
        $expect = array("", null);
        $this->assertEquals($expect, $result);

        //unequal tags
        $result = $this->latexit_base->handle("~~~RECURSIVE~~", "", 0, new Doku_Handler());
        $expect = false;
        $this->assertEquals($expect, $result);
    }

    /**
     * Testing render method.
     */
    public function test_render() {
        //test recursive inserting part of method with xhtml renderer
        $renderer = new Doku_Renderer_xhtml();
        $data = array("", 4);
        $result = $this->latexit_base->render("xhtml", $renderer, $data);
        $this->assertEquals("<h4>Next link is recursively inserted.</h4>", $renderer->doc);
        $this->assertTrue($result);

        //nothing recognized
        $renderer = new Doku_Renderer_xhtml();
        $data = array("", null);
        $result = $this->latexit_base->render("xhtml", $renderer, $data);
        $this->assertEquals("", $renderer->doc);
        $this->assertFalse($result);
        
        //test recursive inserting part of method with latex renderer
        $renderer = new renderer_plugin_latexit();
        $data = array("", 4);
        $result = $this->latexit_base->render("latex", $renderer, $data);
        $this->assertEquals("", $renderer->doc);
        $this->assertTrue($result);

        //test with not implemented rendering mode
        $result = $this->latexit_base->render("doc", $renderer, $data);
        $this->assertFalse($result);        
    }




    /**
     * Testing handle method.
     */
    public function test_handle_pageinput() {
        //test recursive insertion part of the method
        $matches = array(
            "\\inputwikipage{test:page}",
            "\\inputwikipage{page|Title}",
            "\\inputwikipage[]{test:page}",
            "\\inputwikipage[4]{test:page}"
        );
        $expect = array(
            array('', 1, 'test:page', ''),
            array('', 1, 'page',      'Title'),
            array('', 1, 'test:page', ''),
            array('', 4, 'test:page', '')
        );
        foreach($matches as $i => $match) {
            $result = $this->latexit_inputwikipage->handle($match, "", 0, new Doku_Handler());
            $this->assertEquals($expect[$i], $result);
        }
    }

    public function test_handle_invalidsyntaxes_pageinput() {
        $matches = array(
            // no number
            "\\inputwikipage[es]{page|Title}",
            // too high level, less than 1
            "\\inputwikipage[0]{test:page}",
            // too low level, more than 5
            "\\inputwikipage[8]{test:page}"
        );
        $expect = array(
            array('', 1, 'page',      'Title'),
            array('', 1, 'test:page', ''),
            array('', 5, 'test:page', '')
        );

        foreach($matches as $i => $match) {
            $result = $this->latexit_inputwikipage->handle($match, "", 0, new Doku_Handler());
            $this->assertEquals($expect[$i], $result);
        }
    }

    /**
     * Testing render method.
     */
    public function test_render_pageinput_xhtml() {

        saveWikiText('test:page', 'This page is only included in Latex', 'Test setup');

        //test recursive inserting part of method with xhtml renderer
        $datainputs = array(
            array('', 1, 'test:page', ''),
            array('', 1, 'page',      'Title'),
            array('', 2, 'test:page', ''),
            array('', 4, 'test:page', '')
        );

        $renderer = new Doku_Renderer_xhtml();
        foreach($datainputs as $i => $data) {
            $result = $this->latexit_inputwikipage->render("xhtml", $renderer, $data);

            $this->assertContains("<h{$datainputs[$i][1]}>Next link is recursively inserted.</h{$datainputs[$i][1]}>", $renderer->doc);
            $this->assertContains('href="'.wl($datainputs[$i][2]).'"', $renderer->doc);
            $this->assertTrue($result);
        }
    }

    /**
     * Testing render method.
     */
    public function test_render_base_latex() {
        saveWikiText('page1', "======First======\n 1 ~~~~~~RECURSIVE~~~~~~\n [[test:page]]\n ======Second======\n ", 'Base page1');
        saveWikiText('page2', "======First======\n 2 ~~~~~~RECURSIVE~~~~~~\n [[test:nonexist]]\n ======Second======\n ", 'Base page2');
        saveWikiText('page3', "======First======\n 3 ~~RECURSIVE~~\n [[test:nonexist]]\n ======Second======\n ", 'Base page2');
        saveWikiText('page4', "======First======\n 4 ~~~RECURSIVE~~~\n [[test:page]]\n ======Second======\n ", 'Base page31');
        saveWikiText('page5', "======First======\n 5 ~~~~RECURSIVE~~~~\n [[test:page]]\n ~~~~~RECURSIVE~~~~~\n [[test:page]] \n ======Second======\n ", 'Base page4');

        saveWikiText('test:page', '======H1======'."\n".'This page is only included in Latex' , 'Add a page to include');

        $this->pageincludingtests();
    }

    /**
     * Testing render method.
     */
    public function test_render_pageinput_latex() {

        saveWikiText('page1', "======First======\n 1 \\inputwikipage{test:page} \n ======Second======\n ", 'Base page1');
        saveWikiText('page2', "======First======\n 2 \\inputwikipage{test:nonexist} \n ======Second======\n ", 'Base page2');
        saveWikiText('page3', "======First======\n 3 \\inputwikipage[5]{test:nonexist} \n ======Second======\n ", 'Base page2');
        saveWikiText('page4', "======First======\n 4 \\inputwikipage[4]{test:page} \n ======Second======\n ", 'Base page31');
        saveWikiText('page5', "======First======\n 5 \\inputwikipage[3]{test:page} \\inputwikipage[2]{test:page} \n ======Second======\n ", 'Base page4');

        saveWikiText('test:page', '======H1======'."\n".'This page is only included in Latex' , 'Add a page to include');

        $this->pageincludingtests();

    }

    /**
     * Tests for checking include actions
     */
    protected function pageincludingtests() {
        //test recursive inserting part of method with xhtml renderer
        $pages = array(
            array('page1', 1, array('\section{')),
            array('page1', 1, array('\section{')),
            array('page2', 0, array('')),
            array('page3', 0, array('')),
            //different levels of indenting verifies whether not an wrong cache is used
            array('page4', 1, array('\paragraph{')),
            array('page5', 2, array('\subsubsection{', '\subsection{'))
        );

        foreach($pages as $page) {

            $output = p_cached_output(wikiFN($page[0], ''), 'latexit');

            //no include message
            $this->assertNotContains("Next link is recursively inserted.", $output);
            //header before and after should not change
            $this->assertContains('\section{\texorpdfstring{First}{First}}', $output);
            $this->assertContains('\section{\texorpdfstring{Second}{Second}}', $output);

            //check inserted page
            $actualcount = preg_match_all("/%RECURSIVELY INSERTED FILE START.*?%RECURSIVELY INSERTED FILE END/s", $output, $matches);
            $this->assertEquals($page[1], $actualcount);

            foreach($matches[0] as $i => $match) {
                if($match !== null) {
                    $this->assertContains($page[2][$i], $match);
                } else {
                    $this->assertContains('% PAGE DON\'T EXISTS', $output);
                }
            }
        }
    }

    public function test_cacheexpiring_inputwikipage() {
        echo "A\n";
        $filename = 'justapage1';
        $includefile = 'test:test';
        saveWikiText($filename, "======First======\n 1 \\inputwikipage{" . $includefile . "} \n ======Second======\n ", 'Base page1');
        idx_addPage($filename);
        $this->pageexpiringtests($filename, $includefile);

        echo "B\n";
        //indent uses a different cachefile
        $filename = 'justapage2';
        saveWikiText($filename, "======First======\n 2 \\inputwikipage[3]{" . $includefile . "} \n ======Second======\n ", 'Base page1');
        idx_addPage($filename);
        $this->pageexpiringtests($filename, $includefile);
        echo "C\n";
    }

    public function test_cacheexpiring_base() {
        echo "D\n";
        $filename = 'justapage3';
        $includefile = 'test:test2';
        saveWikiText($filename, "======First======\n 3 ~~~~~~RECURSIVE~~~~~~\n [[$includefile]]\n ======Second======\n ", 'Base page1');
        idx_addPage($filename);
        $this->pageexpiringtests($filename, $includefile);

        echo "E\n";
        //indent uses a different cachefile
        $filename = 'justapage4';
        saveWikiText($filename, "======First======\n 4 ~~~RECURSIVE~~~\n [[$includefile]]\n ======Second======\n ", 'Base page1');
        idx_addPage($filename);
        $this->pageexpiringtests($filename, $includefile);
        echo "F\n";
    }

    /**
     * Test including of changed files
     *
     * @param $filename
     * @param $includefile
     */
    protected function pageexpiringtests($filename, $includefile) {
        saveWikiText($includefile, "======H1======\n Page content ", 'Add a page to include');
        idx_addPage($includefile);

        $output = p_cached_output(wikiFN($filename, ''), 'latexit');
        $this->assertContains('Page content', $output);

        saveWikiText($includefile, "======H1======\n Changed content ", 'Add a page to include');
        idx_addPage($includefile);

        $output = p_cached_output(wikiFN($filename, ''), 'latexit');
        $this->assertNotContains('Page content', $output);
        $this->assertContains('Changed content', $output);
    }

}
