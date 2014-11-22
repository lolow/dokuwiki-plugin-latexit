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
    protected $s;

    /**
     * Prepares the testing environment.
     */
    public function setUp() {
        parent::setUp();

        $this->s = new syntax_plugin_latexit_base();
    }

    /**
     * Testing getType method.
     */
    public function test_getType() {
        $this->assertEquals("substition", $this->s->getType());
    }

    /**
     * Testing isSingleton method.
     */
    public function test_isSingleton() {
        $this->assertTrue($this->s->isSingleton());
    }

    /**
     * Testing handle method.
     */
    public function test_handle() {
        //test recursive insertion part of the method
        $result = $this->s->handle("~~~RECURSIVE~~~", "", 0, new Doku_Handler());
        $expect = array("", 4);
        $this->assertEquals($expect, $result);
    }
    public function test_handle_invalidsyntaxes() {
        //too long, more than 6 ~
        $result = $this->s->handle("~~~~~~~RECURSIVE~~~~~~~", "", 0, new Doku_Handler());
        $expect = array("", 1);
        $this->assertEquals($expect, $result);

        //too short, only 1 ~
        $result = $this->s->handle("~RECURSIVE~", "", 0, new Doku_Handler());
        $expect = array("", null);
        $this->assertEquals($expect, $result);

        //unequal tags
        $result = $this->s->handle("~~~RECURSIVE~~", "", 0, new Doku_Handler());
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
        $result = $this->s->render("xhtml", $renderer, $data);
        $this->assertEquals("<h4>Next link is recursively inserted.</h4>", $renderer->doc);
        $this->assertTrue($result);

        //nothing recognized
        $renderer = new Doku_Renderer_xhtml();
        $data = array("", null);
        $result = $this->s->render("xhtml", $renderer, $data);
        $this->assertEquals("", $renderer->doc);
        $this->assertFalse($result);
        
        //test recursive inserting part of method with latex renderer
        $renderer = new renderer_plugin_latexit();
        $data = array("", 4);
        $result = $this->s->render("latex", $renderer, $data);
        $this->assertEquals("", $renderer->doc);
        $this->assertTrue($result);

        //test with not implemented rendering mode
        $result = $this->s->render("doc", $renderer, $data);
        $this->assertFalse($result);        
    }

}
