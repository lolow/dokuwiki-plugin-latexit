<?php

/**
 * DokuWiki Plugin latexit (Renderer Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Adam Kučera <adam.kucera@wrent.cz>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

/**
 * Latexit plugin extends default renderer class in this file
 */

/**
 * includes additional plugin classes
 */
require_once DOKU_INC . 'lib/plugins/latexit/classes/Package.php';
require_once DOKU_INC . 'lib/plugins/latexit/classes/RowspanHandler.php';
require_once DOKU_INC . 'lib/plugins/latexit/classes/LabelHandler.php';
require_once DOKU_INC . 'lib/plugins/latexit/classes/RecursionHandler.php';

/**
 * Main latexit class, specifies how will be latex rendered
 */
class renderer_plugin_latexit extends Doku_Renderer {

    /**
     * Singleton helper plugin to store data for multiple renderer instances
     *
     * @var helper_plugin_latexit
     */
    protected $store;

    /**
     * Stores the information about last list level
     *
     * @var int
     */
    protected $last_level;

    /**
     * Is true when the renderer is in a list
     *
     * @var boolean
     */
    protected $list_opened;

    /**
     * Is true when recursive inserting should be used.
     *
     * @var bool
     */
    protected $recursive;

    /**
     * Stores the information about the number of cells found in a table row.
     *
     * @var int
     */
    protected $cells_count;

    /**
     * Stores the information about the number a table cols.
     *
     * @var int
     */
    protected $table_cols;

    /**
     * Stores the last colspan in a table.
     *
     * @var int
     */
    protected $last_colspan;

    /**
     * Stores the last rowspan in a table.
     *
     * @var int
     */
    protected $last_rowspan;

    /**
     * Stores the last align of a cell in a table.
     *
     * @var int
     */
    protected $last_align;

    /**
     * Is true when renderer is inside a table.
     *
     * @var bool
     */
    protected $in_table;

    /**
     * An instance of a RowspanHandler class.
     *
     * @var RowspanHandler
     */
    protected $rowspan_handler;

    /**
     * Is set on true if the document contains media.
     *
     * @var boolean
     */
    protected $media;

    /**
     * This handler makes all the header labels unique
     *
     * @var LabelHandler
     */
    protected $label_handler;

    /**
     * This handler prevents recursive inserting of subpages to be an unending loop.
     *
     * @var RecursionHandler
     */
    protected $recursion_handler;

    /**
     * Constructor
     *
     * Initializes the storage helper
     */
    public function __construct() {
        $this->_initializeStore();
    }

    /**
     * Make available as LaTeX renderer
     */
    public function canRender($format) {
        if($format == 'latex') {
            return true;
        }
        return false;
    }

    /**
     * Return the rendering format of the renderer - latex
     */
    public function getFormat() {
        return 'latex';
    }

    /**
     * Renderer is always created as a new instance.
     * It is required for recursive export.
     */
    public function isSingleton() {
        return false;
    }

    /**
     * Read first config settings as set within the document
     *
     * @param string $setting the setting to access
     * @param mixed  $notset  what to return if the setting is not available
     * @return mixed
     */
    function getConf($setting, $notset = false) {
        global $ID;
        $opts = p_get_metadata($ID, 'plugin_latexit');
        if($opts && isset($opts[$setting])) {
            return $opts[$setting];
        }

        return parent::getConf($setting, $notset);
    }

    /**
     * function is called, when a document is started to being rendered.
     * It inicializes variables, adds headers to the LaTeX document and
     * sets the browser headers of the exported file.
     */
    public function document_start() {
        //register global variables used for recursive rendering
        global $zip;
        //ID stores the current page id with namespaces, required for recursion prevention
        global $ID;

        if(is_null($this->store)) {
            $this->_initializeStore();
        }

        //initialize variables
        $this->store->incrementRecursionDepth();
        $this->list_opened = false;
        $this->recursive = false;
        $this->in_table = false;
        $this->rowspan_handler = new RowspanHandler();
        $this->media = false;
        $this->label_handler = LabelHandler::getInstance();
        $this->recursion_handler = RecursionHandler::getInstance();

        //export of the main document
        if(!$this->store->isImmersed()) {
            //the parent documented cannot be recursively inserted somewhere
            $this->recursion_handler->insert(wikiFN($ID));

            //prepare ZIP archive (will not be created, if it isn't necessary)
            $zip = new ZipArchive();
            $this->_prepareZIP();

            // configure language
            $document_lang = $this->getConf('document_lang');
            $pckg = new Package('babel');
            $pckg->addParameter($document_lang);
            $this->store->addPackage($pckg);

            // encoding is always UTF-8
            $pckg = new Package('inputenc');
            $pckg->addParameter('utf8x');
            $this->store->addPackage($pckg);

            // add metadata to preamble
            $this->store->addPreamble(array('date', '\today')); // FIXME use the document's date instead
            $this->store->addPreamble(array('title', $this->getConf('title')));
            $this->store->addPreamble(array('author', $this->getConf('author')));

            // start document
            $this->_latexcommand('begin', 'document', 2);

            //if title or author or date is set, it prints it
            if($this->getConf('date') || $this->getConf('title') != "" || $this->getConf('author') != "") {
                $this->_latexcommand('maketitle');
            }
            //if table of contents should be displayed, it prints it
            if($this->getConf('table_of_content')) {
                $this->_latexcommand('tableofcontents', null, 2);
            }
        }
    }

    /**
     * Prefix the created document with the pramble and packages
     */
    protected function document_prefix() {
        // copy current doc and reset
        $doc = $this->doc;
        $this->doc = '';

        //get document settings
        $params = array(
            $this->getConf('paper_size'),
            $this->getConf('output_format'),
            $this->getConf('font_size') . 'pt'
        );
        if($this->getConf('landscape')) {
            $params[] = 'landscape';
        }
        if($this->getConf('draft')) {
            $params[] = 'draft';
        }

        // print document settings
        $this->_latexcommand('documentclass', $this->getConf('document_class'), 1, $params);

        // print the packages
        $packages = $this->store->getPackages();
        foreach($packages as $package) {
            /** @var  Package $package */
            $this->doc .= $package->printUsePackage();
        }

        // print the preamble
        $preamble = $this->store->getPreamble();
        foreach($preamble as $command) {
            if(is_array($command)) {
                $this->_latexcommand($command[0], $command[1], $command[2], $command[3]);
            } else {
                $this->doc .= $command;
            }
        }

        // add custom document header
        $this->doc .= $this->getConf('document_header');

        // finally readd the previously created document
        $this->doc .= $doc;
    }

    /**
     * function is called, when a document ends its rendering to finish the document
     * It finalizes the document.
     */
    public function document_end() {
        /** @var ZipArchive $zip */
        global $zip;
        global $ID;

        //if a media were inserted in a recursively added file, we have to push this information up
        $this->_checkMedia();

        if($this->store->isImmersed()) {
            //this is RECURSIVELY added file
            //signal to the upper document, that we inserted media to ZIP archive
            if($this->media) {
                $this->doc .= '%///MEDIA///';
            }

        } else {
            //this is MAIN PAGE of exported file, we can finalize document

            $this->_newline(2);

            /** @var helper_plugin_zotero_bibliography $bibliography */
            $bibliography = plugin_load('helper', 'zotero_bibliography');
            $hasBibliography = $bibliography && !$bibliography->isEmpty();

            if($hasBibliography) {
                $this->_latexcommand('bibliographystyle', $this->getConf('bibliography_style'));
                $this->_latexcommand('bibliography', $this->getConf('bibliography_name'), 2);
            }

            $this->doc .= $this->getConf('document_footer');
            $this->_latexcommand('end', 'document');

            // the document is done, add the prefix
            $this->document_prefix();

            $this->_deleteMediaSyntax();
            //finalize rendering of few entities
            $this->_highlightFixme();
            $this->_removeEntities();
            $this->_fixImageRef();

            $latexoutputfilename = 'output' . time() . '.latex';

            if($this->media || $hasBibliography) {
                //file to download will be ZIP archive

                $zipfilename = $zip->filename;
                if($hasBibliography) {
                    $zip->addFromString($this->getConf('bibliography_name') . '.bib', $bibliography->getBibliography());
                }
                $zip->addFromString($latexoutputfilename, $this->doc);

                //zip archive is created when this function is called,
                //so if no ZIP is needed, nothing is created
                $zip->close();

                $headers = array(
                    'Content-Type' => 'application/zip',
                    'Content-Disposition' => 'attachment; filename=output' . time() . '.zip'
                );

                // Replace latex file by zip file
                $this->doc = file_get_contents($zipfilename);

                //delete temporary zip file
                unlink($zipfilename);
            } else {
                //file to download will be ordinary LaTeX file

                //set the headers, so the browsers knows, this is not the HTML file
                $headers = array(
                    'Content-Type' => 'application/x-latex',
                    'Content-Disposition' => "attachment; filename=$latexoutputfilename;",
                );
            }

            // store the content type headers in metadata
            p_set_metadata($ID, array('format' => array('latexit' => $headers)));
        }

        $this->store->decrementRecursionDepth();
    }

    /**
     * Function is called, when renderer finds a new header.
     * It calls the LaTeX command for an appropriate level.
     *
     * @param string $text  Text of the header
     * @param int    $level Level of the header.
     * @param int    $pos   Not used in LaTeX
     */
    public function header($text, $level, $pos) {
        //package hyperref will enable PDF bookmarks
        $package = new Package('hyperref');
        $package->addParameter('unicode');
        $this->store->addPackage($package);

        //set the types of headers to be used depending on configuration
        $levels = array();
        if($this->getConf('header_part')) {
            $levels[] = 'part';
        }
        if($this->getConf('header_chapter') && $this->getConf('document_class') != 'article') {
            $levels[] = 'chapter';
        }
        array_push($levels, 'section', 'subsection', 'subsubsection', 'paragraph', 'subparagraph');

        //when document is recursively inserted, it will continue from the requested indented header level
        $level += $this->store->getHeaderIndent();

        $this->_newline(2);

        //the array of levels is indexed from 0
        $level--;

        //such a level exists in the array
        if(isset($levels[$level])) {
            $this->_header($levels[$level], $text);
        } //level not in array, use default
        else {
            //to force a newline in latex, there has to be some empty char before, e.g. ~
            $this->doc .= '~';
            $this->_latexcommand('newline');
            $this->_latexcommand('textbf', $this->_latexSpecialChars($text));
        }
        //add a label, so each section can be referenced
        $label = $this->label_handler->newLabel($this->_createLabel($text));
        $this->_latexcommand('label', 'sec:' . $label);
    }

    /**
     * Basic function called, when a text not from DokuWiki syntax is read
     * It adds the data to the document, potentionally dangerous characters for
     * LaTeX are escaped or removed.
     *
     * @param string $text Text to be inserted.
     */
    public function cdata($text) {
        $this->doc .= $this->_latexSpecialChars($text);
    }

    /**
     * Function is called, when renderer finds a new paragraph.
     * It makes new paragraph in LaTeX Document.
     */
    public function p_open() {
        $this->_newline(2);
    }

    /**
     * Function is called, when renderer finds a linebreak.
     * It adds new line in LaTeX Document.
     */
    public function linebreak() {
        if($this->in_table) {
            //in tables in LaTeX there is different syntax
            $this->doc .= "\\newline ";
        } else {
            $this->doc .= "\\\\";
        }
    }

    /**
     * Function is called, when renderer finds a horizontal line.
     * It adds centered horizontal line in LaTeX Document.
     */
    public function hr() {
        $this->_newline(2);
        $this->_latexcommand('begin', 'center');
        $this->doc .= "\line(1,0){250}\n";
        $this->_latexcommand('end', 'center', 2);
    }

    /**
     * function is called, when renderer finds a strong text
     * It calls command for strong text in LaTeX Document.
     */
    public function strong_open() {
        $this->_open('textbf');
    }

    /**
     * function is called, when renderer finds the end of a strong text
     */
    public function strong_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds an emphasised text
     * It calls command for emphasised text in LaTeX Document.
     */
    public function emphasis_open() {
        $this->_open('emph');
    }

    /**
     * function is called, when renderer finds the end of an emphasised text
     */
    public function emphasis_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds an underlined text
     * It calls command for underlined text in LaTeX Document.
     */
    public function underline_open() {
        $this->_open('underline');
    }

    /**
     * function is called, when renderer finds the end of an underlined text
     */
    public function underline_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds a monospace text
     * (all letters have same width)
     * It calls command for monospace text in LaTeX Document.
     */
    public function monospace_open() {
        $this->_open('texttt');
    }

    /**
     * function is called, when renderer finds the end of a monospace text
     * (all letters have same width)
     */
    public function monospace_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds a subscript
     * It adds needed package and calls command for subscript in LaTeX Document.
     */
    public function subscript_open() {
        $package = new Package('fixltx2e');
        $this->store->addPackage($package);
        $this->_open('textsubscript');
    }

    /**
     * function is called, when renderer finds the end of a subscript
     */
    public function subscript_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds a superscript
     * It adds needed package and calls command for superscript in LaTeX Document.
     */
    public function superscript_open() {
        $package = new Package('fixltx2e');
        $this->store->addPackage($package);
        $this->_open('textsuperscript');
    }

    /**
     * function is called, when renderer finds the end of a superscript
     */
    public function superscript_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds a deleted text
     * It adds needed package and calls command for deleted text in LaTeX Document.
     */
    public function deleted_open() {
        $package = new Package('ulem');
        $package->addParameter('normalem');
        $this->store->addPackage($package);
        $this->_open('sout');
    }

    /**
     * function is called, when renderer finds the end of a deleted text
     */
    public function deleted_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds a footnote
     * It calls footnote command in LaTeX Document.
     */
    public function footnote_open() {
        $this->_open('footnote');
    }

    /**
     * function is called, when renderer finds the end of a footnote
     */
    public function footnote_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds start of an unordered list
     * It calls command for an unordered list in latex, even with right indention
     */
    public function listu_open() {
        $this->_list_open("itemize");
    }

    /**
     * function is called, when renderer finds the end of an unordered list
     * It calls command for the end of an unordered list in latex, even with right indention
     */
    public function listu_close() {
        $this->_list_close("itemize");
    }

    /**
     * function is called, when renderer finds start of an ordered list
     * It calls command for an ordered list in latex, even with right indention
     */
    public function listo_open() {
        $this->_list_open("enumerate");
    }

    /**
     * function is called, when renderer finds the end of an ordered list
     * It calls command for the end of an ordered list in latex, even with right indention
     */
    public function listo_close() {
        $this->_list_close("enumerate");
    }

    /**
     * function is called, when renderer finds start of a list item
     * It calls command for a list item in latex, even with right indention
     *
     * @param int  $level Level of indention.
     * @param bool $node  true when a node; false when a leaf
     */
    public function listitem_open($level, $node = false) {
        $this->last_level = $level;
        $this->_indent_list();
        $this->doc .= "  ";
        $this->_latexcommand('item', null, 0);
    }

    /**
     * function is called, when renderer finds the end of a list item content
     * It adds newline to the latex file.
     */
    public function listcontent_close() {
        $this->_newline();
    }

    /**
     * Original text is not formatted by DW, so this function just inserts the text as it is.
     * It just escapes special characters.
     *
     * @param string $text Unformatted text.
     */
    public function unformatted($text) {
        $this->doc .= $this->_latexSpecialChars($text);
    }

    /**
     * Inserts PHP code to the document.
     *
     * @param string $text PHP code.
     */
    public function php($text) {
        $this->code($text, "PHP");
    }

    /**
     * Inserts block of PHP code to the document.
     *
     * @param string $text PHP code.
     */
    public function phpblock($text) {
        $this->code($text, "PHP");
    }

    /**
     * Inserts HTML code to the document.
     *
     * @param string $text HTML code.
     */
    public function html($text) {
        $this->code($text, "HTML");
    }

    /**
     * Inserts block of HTML code to the document.
     *
     * @param string $text HTML code.
     */
    public function htmlblock($text) {
        $this->code($text, "HTML");
    }

    /**
     * Inserts preformatted text (with all whitespaces)
     *
     * @param string $text Preformatted text.
     */
    public function preformatted($text) {
        $this->_newline();
        $this->_latexcommand('begin', 'verbatim');
        $this->doc .= $text;
        $this->_newline();
        $this->_latexcommand('end', 'verbatim');
    }

    /**
     * Opens the quote environment.
     */
    public function quote_open() {
        $this->_newline();
        $this->_latexcommand('begin', 'quote');
    }

    /**
     * Closes the quote environment.
     */
    public function quote_close() {
        $this->_newline();
        $this->_latexcommand('end', 'quote');
    }

    /**
     * File tag is almost the same like the code tag, but it enables to download
     * the code directly from DW.
     * Therefore we just add the filename to the top of code.
     *
     * @param string $text The code itself.
     * @param string $lang Programming language.
     * @param string $file The code will be exported from DW as a file.
     */
    public function file($text, $lang = null, $file = null) {
        $this->code($text, $lang, $file);
    }

    /**
     * Function adds a block of programming language code to LaTeX file
     * using the listings package.
     *
     * @param string $text The code itself.
     * @param string $lang Programming language.
     * @param string $file The code can be inserted to DokuWiki as a file.
     */
    public function code($text, $lang = null, $file = null) {
        $pckg = new Package('listings');
        $this->store->addPackage($pckg);

        //start code block
        $this->_open('lstset');
        $this->doc .= 'frame=single';
        if(!is_null($lang)) {
            //if language name is specified, insert it to LaTeX
            $this->doc .= ', language=';
            $this->doc .= $this->_latexSpecialChars($lang);
        }
        //insert filename
        if(!is_null($file)) {
            $this->doc .= ', title=';
            $this->doc .= $this->_latexSpecialChars($file);
        }
        $this->_close();
        $this->_newline();
        //open the code block
        $this->_latexcommand('begin', 'lstlisting');

        //get rid of some non-standard characters
        $text = str_replace('”', '"', $text);
        $text = str_replace('–', '-', $text);
        $this->doc .= $text;
        //close the code block
        $this->_latexcommand('end', 'lstlisting', 2);
    }

    /**
     * This function is called when an acronym is found. It just inserts it as a classic text.
     * I decided not to implement the mouse over text, although it is possible, but
     * it does not work in all PDF browsers.
     * http://tex.stackexchange.com/questions/32314/is-there-an-easy-way-to-add-hover-text-to-all-incidents-of-math-mode-where-the-h
     *
     * @param string $acronym The Acronym.
     */
    public function acronym($acronym) {
        $this->doc .= $this->_latexSpecialChars($acronym);
    }

    /**
     * This function is called when a smiley is found.
     * LaTeX does not support smileys, so they are inserted as a normal text.
     * FIXME and DELETEME are exceptions, they are highlited (in the end of exporting).
     *
     * @param string $smiley Smiley chars.
     */
    public function smiley($smiley) {
        if($smiley == 'FIXME' || $smiley == 'DELETEME') {
            $pckg = new Package('soul');
            $this->store->addPackage($pckg);
            $this->doc .= $smiley;
        } else {
            $this->doc .= $this->_latexSpecialChars($smiley);
        }
    }

    /**
     * DocuWiki can represent some characters as they typograficaly correct entities.
     * Most of them exist in LaTeX as well, but some only in math mode.
     *
     * @param string $entity An entity.
     */
    public function entity($entity) {
        //this text is removed after exporting
        //it is here to disallow double escaping of some math characters
        $this->doc .= '///ENTITYSTART///';
        switch($entity) {
            case '->':
                $this->doc .= '$\rightarrow$';
                break;
            case '<-':
                $this->doc .= '$\leftarrow$';
                break;
            case '<->':
                $this->doc .= '$\leftrightarrow$';
                break;
            case '=>':
                $this->doc .= '$\Rightarrow$';
                break;
            case '<=':
                $this->doc .= '$\Leftarrow$';
                break;
            case '<=>':
                $this->doc .= '$\Leftrightarrow$';
                break;
            case '(c)':
                $this->doc .= '\copyright ';
                break;
            case '(tm)':
                $this->doc .= '\texttrademark ';
                break;
            case '(r)':
                $this->doc .= '\textregistered ';
                break;
            default:
                $this->doc .= $this->_latexSpecialChars($entity);
                break;
        }
        $this->doc .= '///ENTITYEND///';
    }

    /**
     * Inserts multiply entity (eg. 640x480) to LaTeX file.
     *
     * @param int $x First number
     * @param int $y Second number
     */
    public function multiplyentity($x, $y) {
        $this->doc .= '///ENTITYSTART///';
        $this->doc .= '$';
        $this->doc .= $this->_latexSpecialChars($x);
        $this->doc .= ' \times ';
        $this->doc .= $this->_latexSpecialChars($y);
        $this->doc .= '$';
        $this->doc .= '///ENTITYEND///';
    }

    /**
     * Inserts single quote opening to LaTeX depending on set language.
     */
    public function singlequoteopening() {
        $this->doc .= '`';
    }

    /**
     * Inserts single quote closing to LaTeX depending on set language.
     */
    public function singlequoteclosing() {
        $this->doc .= '\'';
    }

    /**
     * Inserts apostrophe to LaTeX depending on set language.
     */
    public function apostrophe() {
        $this->doc .= '\'';
    }

    /**
     * Inserts double quote opening to LaTeX depending on set language.
     * Support for only English and Czech is implemented.
     */
    public function doublequoteopening() {
        switch($this->getConf('document_lang')) {
            /* This is bugging, DW parses it strangely... FIXME consultation
             * case 'czech':
              $this->_open('uv');
              break; */
            default :
                $this->doc .= ',,';
                break;
        }
    }

    /**
     * Inserts double quote closing to LaTeX depending on set language.
     * Support for only English and Czech is implemented.
     */
    public function doublequoteclosing() {
        switch($this->getConf('document_lang')) {
            /* This is bugging, DW parses it strangely... FIXME consultation
              case 'czech':
              $this->_close();
              break; */
            default :
                $this->doc .= '"';
                break;
        }
    }

    /**
     * Function is called, when renderer finds a link written in text like CamelCase.
     * It just calls the common link function.
     *
     * @param string $link Internal link to a wiki page.
     */
    public function camelcaselink($link) {
        $this->internallink($link, $link);
    }

    /**
     * This function handles the links on the page itself (#something at the end of URL)
     * It inserts reference to LaTeX document
     *
     * @param string $hash Label of a section
     * @param string $name Text of the original link
     */
    public function locallink($hash, $name = null) {
        $this->_insertLinkPackages();
        if(!is_null($name)) {
            $this->doc .= $this->_latexSpecialChars($name);
        } else {
            $this->doc .= $this->_latexSpecialChars($hash);
        }
        $this->doc .= ' (';
        $this->_latexcommand('autoref', "sec:" . $hash, 0);
        $this->doc .= ')';
    }

    /**
     * function is called, when renderer finds an internal link
     * It resolves the internal link (namespaces, URL)
     * Depending on the configuration (inside the document):
     *     It handles link as an external and calls proper function in LaTeX depending on the title
     *     It recursively adds the linked page to the exported LaTeX file
     * This feature is not in classic plugin configuration.
     * If you want to have a link recursively inserted, add ~~RECURSIVE~~ just before it.
     * The count of ~ means the same as = for headers. It will determine the
     * level of first header used in recursively inserted text.
     *
     * @param string $link  Internal link (can be without proper namespace)
     * @param        string /array $title Title, can be null or array (if it is media)
     */
    public function internallink($link, $title = null) {
        //register globals
        global $ID; //in this global var DokuWiki stores the current page id with namespaces

        $link_original = $link;

        //get current namespace from current page
        $current_namespace = getNS($ID);
        //get the page ID with right namespaces
        //$exists stores information, if the page exists.
        resolve_pageid($current_namespace, $link, $exists);

        if($this->recursive) {
            $this->insertWikipage($link, $exists);

            //add info for cache expiring to metadata
            // TODO this metadata is generated at latex rendering, is that sometimes too late? And is it stored always ok?
            @list($page) = explode('#', $link, 2);
            $pluginmetadata = p_get_metadata($ID, 'plugin_latexit', METADATA_RENDER_USING_SIMPLE_CACHE);
            $pluginmetadata['insertedpages'][$page] = $exists;

            // does this update the metadata lastmod date too early?
            p_set_metadata($ID, array('plugin_latexit' => array('insertedpages' => $pluginmetadata['insertedpages'])));

        } else {
            //if the page does not exist, just insert it as common text
            if(!$exists) {
                $this->cdata($title);
                return;
            }
            //handle internal links as they were external
            $params = '';
            $absoluteURL = true;
            //get the whole URL
            $url = wl($link, $params, $absoluteURL);
            $url = $this->_secureLink($url);

            //escape link title
            if(!is_array($title)) {
                $title = $this->_latexSpecialChars($title);
            }

            $this->_insertLink($url, $title, "internal", $link_original);
        }

        $this->recursive = false;
    }

    /**
     * Set that the next link will be inserted to the file recursively.
     */
    public function markNextInternallinkforInclusion() {
        $this->recursive = true;
    }

    /**
     * Increases header indent with a given number.
     *
     * @param int $increment Size of the increase.
     */
    public function increaseHeaderIndent($increment) {
        $this->store->increaseHeaderIndent($increment);
    }

    /**
     * Insert a wiki page converted to latex commands into document
     *
     * @param string $link
     * @param bool   $exists
     */
    public function insertWikipage($link, $exists) {
        if(!$exists) {
            $this->_newline(2);
            $this->cdata("% PAGE DON'T EXISTS");
            $this->_newline(2);

            //check if it can continue with recursive inserting of this page
        } elseif($this->recursion_handler->disallow(wikiFN($link))) {
            //warn the user about unending recursion
            $this->_newline(2);
            $this->cdata("%!!! RECURSION LOOP HAS BEEN PREVENTED !!!");
            $this->_newline(2);

        } else {
            //insert this page to RecursionHandler
            $this->recursion_handler->insert(wikiFN($link));

            // start parsing linked page - call the latexit plugin again
            $parsedpage = p_cached_output(wikiFN($link), 'latexit', $link);

            $this->_newline(2);
            //insert comment to LaTeX
            $this->cdata("%RECURSIVELY INSERTED FILE START");
            $this->_newline(2);
            //insert parsed data
            $this->doc .= $parsedpage;
            $this->_newline(2);
            //insert comment to LaTeX
            $this->cdata("%RECURSIVELY INSERTED FILE END");
            $this->_newline(2);

            //remove this page from RecursionHandler
            $this->recursion_handler->remove(wikiFN($link));
        }

        //restore headers indent level to previous level
        $this->store->decreaseHeaderIndent();
    }

    /**
     * function is called, when renderer finds an external link
     * It calls proper function in LaTeX depending on the title
     *
     * @param string $link  External link
     * @param        string /array $title Title, can be null or array (if it is media)
     */
    public function externallink($link, $title = null) {
        if(!is_array($title)) {
            $title = $this->_latexSpecialChars($title);
        }
        $link = $this->_secureLink($link);
        $this->_insertLink($link, $title, "external");
    }

    /**
     * InterWiki links lead to another wikis and they can be written in special syntax.
     * This resolves the link and inserts it as normal external link.
     *
     * @param string $link     Original link in DW syntax
     * @param string $title    Title of link, can also be image
     * @param string $wikiName Name of wiki (according to configuration)
     * @param string $wikiUri  Text in link after wiki address
     */
    public function interwikilink($link, $title = null, $wikiName, $wikiUri) {
        $url = $this->_resolveInterWiki($wikiName, $wikiUri);
        if(is_null($title)) {
            $name = $wikiUri;
        } else {
            $name = $title;
        }
        $this->externallink($url, $name);
    }

    /**
     * Inserts a link to a file on local filesystem.
     * It just handles the link as an external link.
     *
     * @param string $link  Link to a file.
     * @param string $title Title of the link, can be image.
     */
    public function filelink($link, $title = null) {
        $this->externallink($link, $title);
    }

    /**
     * Inserts a link to a Windows share intranet server.
     * It just handles the link as an external link.
     *
     * @param string $link  Link to a file.
     * @param string $title Title of the link, can be image.
     */
    public function windowssharelink($link, $title = null) {
        $this->externallink($link, $title);
    }

    /**
     * function is called, when renderer finds an email link
     * It calls proper function in LaTeX depending on the name and sets mailto
     *
     * @param string $address Email address
     * @param        string   /array $name Name, can be null or array (if it is media)
     */
    public function emaillink($address, $name = null) {
        if(!is_array($name)) {
            $name = $this->_latexSpecialChars($name);
        }
        $this->_insertLink($address, $name, "email");
    }

    /**
     * This function is called when an image is uploaded to DokuWiki and inserted to a page.
     * It adds desired commands to the LaTeX file and also downloads the image with LaTeX
     * file in the ZIP archive.
     *
     * @param string      $src     DokuWiki source of the media.
     * @param string|null $title   Mouseover title of image, we dont use this param (use imagareference plugin for correct labeling)
     * @param string|null $align   Align of the media.
     * @param int|null    $width   Width of the media. But DW uses pixels, LaTeX does not. Therefore we dont use it.
     * @param int|null    $height  Height of the media. But DW uses pixels, LaTeX does not. Therefore we dont use it.
     * @param string      $cache   We delete cache, so we don't use this param.
     * @param bool        $linking Not used.
     */
    public function internalmedia($src, $title = null, $align = null, $width = null, $height = null, $cache = null, $linking = null) {
        /** @var ZipArchive $zip */
        global $zip;

        $media_folder = $this->getConf('media_folder');

        if(strpos($src, ':') !== false) {
            //the namespace structure is kept in folder structure in ZIP archive
            $namespaces = explode(':', $src);
            $path = '';
            for($i = 1; $i < count($namespaces); $i++) {
                if($i != 1) {
                    $path .= "/";
                }
                $path .= $namespaces[$i];
            }
        } else {
            $path = $src;
        }

        //find media on FS
        $location = mediaFN($src);
        $exists = file_exists($location);
        if($exists) {
            //exported file will be ZIP archive
            $this->media = true;
            //add media to ZIP archive
            $zip->addFile($location, $media_folder . "/" . $path);
        }

        $mime = mimetype($src);
        if(substr($mime[1], 0, 5) == "image") {
            $this->_insertImage($path, $align, $media_folder);
        } else {
            $this->_insertFile($path, $title, $media_folder);
        }
    }

    /**
     * This function is called when an image from the internet is inserted to a page.
     * It adds desired commands to the LaTeX file and also downloads the image with LaTeX
     * file in the ZIP archive.
     *
     * @param string      $src     URL source of the media.
     * @param string|null $title   Mouseover title of image, we dont use this param (use imagareference plugin for correct labeling)
     * @param string      $align   Align of the media.
     * @param int|null    $width   Width of the media. But DW uses pixels, LaTeX does not. Therefore we dont use it.
     * @param int|null    $height  Height of the media. But DW uses pixels, LaTeX does not. Therefore we dont use it.
     * @param string|null $cache   We delete cache, so we don't use this param.
     * @param bool|null   $linking Not used.
     */
    public function externalmedia($src, $title = null, $align = null, $width = null, $height = null, $cache = null, $linking = null) {
        global $conf;
        /** @var ZipArchive $zip */
        global $zip;

        $this->media = true;
        $media_folder = $this->getConf('media_folder');

        //get just the name of file without path
        $filename = basename($src);
        //download the file to the DokuWiki TEMP folder
        $location = $conf["tmpdir"] . "/" . $filename;
        file_put_contents($location, file_get_contents($src));
        //add file to the ZIP archive
        $path = $media_folder . "/" . $filename;
        $zip->addFile($location, $path);

        $mime = mimetype($filename);

        if(substr($mime[1], 0, 5) == "image") {
            $this->_insertImage($filename, $align, $media_folder);
        } else {
            $this->_insertFile($filename, $title, $media_folder);
        }
    }

    /**
     * Function is called, when a renderer finds a start of an table.
     * It inserts needed packages and the header of the table.
     *
     * @param int $maxcols Maximum of collumns in the table
     * @param int $numrows Number of rows in table (not required in LaTeX)
     * @param int $pos     This parameter is not required by LaTeX.
     */
    public function table_open($maxcols = null, $numrows = null, $pos = null) {
        $this->table_cols = $maxcols;

        //set environment to tables
        $this->in_table = true;
        $pckg = new Package('longtable');
        $this->store->addPackage($pckg);

        //print the header
        $this->_latexcommand('begin', 'longtable', 0);
        $this->doc .= "{|";
        for($i = 0; $i < $maxcols; $i++) {
            $this->doc .= $this->getConf('default_table_align') . "|";
        }
        $this->_close();
        $this->_newline();
        $this->_latexcommand('hline');
    }

    /**
     * Function is called in the end of every table.
     * It prints the footer of the table.
     *
     * @param int $pos Not required in LaTeX.
     */
    public function table_close($pos = null) {
        //close the table environment
        $this->in_table = false;
        //print the footer
        $this->_latexcommand('end', 'longtable', 2);
    }

    /**
     * Function is called at start of every row in a table.
     */
    public function tablerow_open() {
        //set the number of cells printed
        $this->cells_count = 0;
    }

    /**
     * Function is called at the end of every row in a table
     */
    public function tablerow_close() {
        //add syntax for end of a row
        $this->doc .= " \\\\ ";
        $this->_newline();
        //add line
        $this->_latexcommand('hline');
        $this->doc .= " ";
        $this->_newline();
    }

    /**
     * Function is called when the header row is reached.
     * It just prints regular row in bold.
     *
     * @param int         $colspan
     * @param string|null $align
     * @param int         $rowspan
     */
    public function tableheader_open($colspan = 1, $align = null, $rowspan = 1) {
        $this->tablecell_open($colspan, $align, $rowspan);
        $this->_open('textbf');
    }

    /**
     * Function is called at the end of the header row.
     */
    public function tableheader_close() {
        $this->_close();
        $this->tablecell_close();
    }

    /**
     * Function handling exporting of each cell in a table.
     *
     * @param int    $colspan Sets collspan of the cell.
     * @param string $align   Sets align of the cell.
     * @param int    $rowspan Sets rows[am of the cell.
     */
    public function tablecell_open($colspan = 1, $align = null, $rowspan = 1) {
        if(is_null($align)) {
            $align = $this->getConf('default_table_align');
        } else {
            //in DW align is left, right, center, in LaTeX just first letter
            $align = substr($align, 0, 1);
        }
        //if anything is not standard, we will have to use different closing of a cell
        $this->last_colspan = $colspan;
        $this->last_rowspan = $rowspan;
        $this->last_align = $align;

        //RowspanHandler stores information about the number of cells to be rowspanned
        if($this->rowspan_handler->getRowspan($this->cells_count) != 0) {
            $this->doc .= ' & ';
            $this->rowspan_handler->decreaseRowspan($this->cells_count);
            $this->cells_count++;
        }

        //colspan or not default align
        if($colspan != 1 || $align != $this->getConf('default_table_align')) {
            $this->doc .= "\\multicolumn{" . $colspan . "}{|$align|}{";
        }
        //start a new rowspan using RowspanHandler
        if($rowspan != 1) {
            $pckg = new Package('multirow');
            $this->store->addPackage($pckg);
            $this->rowspan_handler->insertRowspan($rowspan - 1, $this->cells_count);
            $this->doc .= "\\multirow{" . $rowspan . "}{*}{";
        }
    }

    /**
     * Function is called at the end of every cell.
     */
    public function tablecell_close() {
        //colspan or align different from default has been set in this cell
        if($this->last_colspan != 1 || $this->last_align != $this->getConf('default_table_align')) {
            $this->doc .= "}";
        }
        //rowspan has been set in this cell
        if($this->last_rowspan != 1) {
            $this->doc .= "}";
        }

        //are there any cells left in this row?
        $this->cells_count += $this->last_colspan;
        if($this->table_cols != $this->cells_count) {
            $this->doc .= " & ";
        }
    }

    /**
     * Syntax of almost every basic LaTeX command is always the same.
     *
     * @param string $command  The name of a LaTeX command.
     * @param array  $params   Array of parameters of the command
     * @param bool   $brackets Tells if the brackets should be used.
     */
    protected function _open($command, $params = null, $brackets = true) {
        $this->doc .= "\\" . $command;
        //if params are set, print them all
        if(!is_null($params)) {
            $this->doc .= '[';
            $i = 0;
            foreach($params as $p) {
                if($i++ > 0) {
                    $this->doc .= ', ';
                }
                $this->doc .= $p;
            }
            $this->doc .= ']';
        }
        if($brackets) {
            $this->doc .= "{";
        }
    }

    /**
     * Closing tag of a lot of LaTeX commands is always same and will be called
     * in almost every close function.
     */
    protected function _close() {
        $this->doc .= '}';
    }

    /**
     * Helper function for printing almost all regular commands in LaTeX.
     * It can also print newlines after command and it supports parameters.
     *
     * @param string $command  Name of the command.
     * @param string $text     Text to insert into the brackets.
     * @param int    $newlines How many newlines after the command to insert.
     * @param array  $params   Array of parameters to be inserted.
     */
    protected function _latexcommand($command, $text = null, $newlines = 1, $params = null) {
        //if there is no text, there will be no brackets
        if(is_null($text)) {
            $brackets = false;
        } else {
            $brackets = true;
        }
        $this->_open($command, $params, $brackets);
        //if there is no text, there is nothing to be closed
        if(!is_null($text)) {
            $this->doc .= $text;
            $this->_close();
        }
        $this->_newline($newlines);
    }

    /**
     * Function inserting new lines in the LaTeX file.
     *
     * @param int $cnt How many new lines to insert.
     */
    protected function _newline($cnt = 1) {
        for($i = 0; $i < $cnt; $i++) {
            $this->doc .= "\n";
        }
    }

    /**
     * Function checks, if there were media added in a subfile.
     */
    protected function _checkMedia() {
        //check
        if(preg_match('#%///MEDIA///#si', $this->doc)) {
            $this->media = true;
        }
        //and delete any traces
        $this->_deleteMediaSyntax();
    }

    /**
     * Function removes %///MEDIA/// from document
     */
    protected function _deleteMediaSyntax() {
        str_replace('%///MEDIA///', '', $this->doc);
    }

    /**
     * Function inserts package used for hyperlinks.
     */
    protected function _insertLinkPackages() {
        $package = new Package('hyperref');
        //fixes the encoding warning
        $package->addParameter('unicode');
        $this->store->addPackage($package);
    }

    /**
     * Function used for exporting lists, they differ only by command.
     *
     * @param string $command Proper LaTeX list command
     */
    protected function _list_open($command) {
        $this->_newline();
        if($this->list_opened) {
            for($i = 1; $i < $this->last_level + 1; $i++) {
                //indention
                $this->doc .= '  ';
            }
        } else {
            $this->list_opened = true;
        }
        $this->_indent_list();
        $this->_latexcommand('begin', $command);
    }

    /**
     * Function used for exporting the end of lists, they differ only by command.
     *
     * @param string $command Proper LaTeX list command
     */
    protected function _list_close($command) {
        if($this->last_level == 1) {
            $this->list_opened = false;
        }
        $this->_indent_list();
        $this->_latexcommand('end', $command);
    }

    /**
     * Indents the list according to the last seen level.
     */
    protected function _indent_list() {
        for($i = 1; $i < $this->last_level; $i++) {
            $this->doc .= '  ';
        }
    }

    /**
     * This function highlights fixme DW command.
     * This format is used in some DokuWiki instances.
     * FIXME insert into documentation
     * format is: FIXME[author](description of a thing to fix)
     * (this feature comes from CCM at FIT CVUT, for whom I write the plugin)
     */
    protected function _highlightFixme() {
        $this->doc = str_replace('FIXME', '\hl{FIXME}', $this->doc);
        $this->doc = str_replace('DELETEME', '\hl{DELETEME}', $this->doc);
        $this->doc = preg_replace_callback('#{FIXME}\[(.*?)\]\((.*?)\)#si', array(&$this, '_highlightFixmeHandler'), $this->doc);
    }

    /**
     * Function handling parsing of the fix me DW command.
     *
     * @param array $matches of strings $matches strings from the regex
     * @return string regex result replacement
     */
    protected function _highlightFixmeHandler($matches) {
        $matches[1] = $this->_stripDiacritics($matches[1]);
        $matches[2] = $this->_stripDiacritics($matches[2]);
        return '{FIXME[' . $matches[1] . '](' . $matches[2] . ')}';
    }

    /**
     * Insert header to the LaTeX document with right level command.
     *
     * @param string $level LaTeX command for header on right level.
     * @param string $text  Text of the Header.
     */
    protected function _header($level, $text) {
        $this->_open($level);
        //pdflatex can have problems with special chars while making bookmarks
        //this is the fix
        $this->_open('texorpdfstring');
        $text = str_replace("\"", "", $text);
        $this->doc .= $this->_latexSpecialChars($text);
        $this->_close();
        $this->doc .= '{';
        $this->doc .= $this->_pdfString($text);
        $this->_close();
        $this->_close();
        $this->_newline();
    }

    /**
     * Escapes LaTeX special chars.
     * Entities are in the middle of special tags so eg. MathJax texts are not escaped, but entities are.
     *
     * @param string $text Text to be escaped.
     * @return string Escaped text.
     */
    public function _latexSpecialChars($text) {
        return helper_plugin_latexit::escape($text);
    }

    /**
     * Function replaces entities, which have not been replaced using _latexSpecialChars function
     */
    protected function _removeEntities() {
        $this->doc = preg_replace('#///ENTITYSTART///(.*?)///ENTITYEND///#si', '$1', $this->doc);
    }

    /**
     * Functions fixes few problems which come from imagereference plugin.
     */
    protected function _fixImageRef() {
        $this->doc = str_replace('[h!]{\centering}', '[!ht]{\centering}', $this->doc);
        $this->doc = str_replace('\\ref{', '\autoref{', $this->doc);
    }

    /**
     * function replacing some characters in MathJax mode
     *
     * @param string $data Parsed text.
     */
    public function _mathMode($data) {
        $data = str_replace('<=>', '\Leftrightarrow', $data);
        $data = str_replace('<->', '\leftrightarrow', $data);
        $data = str_replace('->', '\rightarrow', $data);
        $data = str_replace('<-', '\leftarrow', $data);
        $data = str_replace('=>', '\Rightarrow', $data);
        $data = str_replace('<=', '\Leftarrow', $data);
        $data = str_replace('...', '\ldots', $data);
        $data = str_replace('−', '-', $data);

        $this->doc .= $data;
    }

    /**
     * Function creates label from a header name.
     *
     * @param string $text A header name.
     * @return string Label
     */
    protected function _createLabel($text) {
        $text = preg_replace('#///ENTITYSTART///(.*?)///ENTITYEND///#si', '$1', $text);
        $text = $this->_stripDiacritics($text);
        $text = strtolower($text);
        $text = str_replace(" ", "_", $text);
        $text = $this->_removeMathAndSymbols($text);
        return $text;
    }

    /**
     * Escapes some characters in the URL.
     *
     * @param string $link The URL.
     * @return string Escaped URL.
     */
    protected function _secureLink($link) {
        $link = str_replace("\\", "\\\\", $link);
        $link = str_replace("#", "\#", $link);
        $link = str_replace("%", "\%", $link);
        $link = str_replace("&", "\&", $link);
        return $link;
    }

    /**
     * Prepares the ZIP archive.
     *
     * @global string     $conf global dokuwiki configuration
     * @global ZipArchive $zip  pointer to our zip archive
     */
    protected function _prepareZIP() {
        global $conf;
        /** @var ZipArchive $zip */
        global $zip;

        //generate filename
        $filename = $conf["tmpdir"] . "/output" . time() . ".zip";
        //create ZIP archive
        if($zip->open($filename, ZipArchive::CREATE) !== true) {
            exit("LaTeXit was not able to open <$filename>, check access rights.\n");
        }
    }

    /**
     * Function prints the image command into the LaTeX file.
     *
     * @param string $path         relative path of the image.
     * @param string $align        image align
     * @param string $media_folder path to the media folder.
     */
    protected function _insertImage($path, $align, $media_folder) {
        $pckg = new Package('graphicx');
        $pckg->addCommand('\\graphicspath{{' . $media_folder . '/}}');
        $this->store->addPackage($pckg);

        //http://stackoverflow.com/questions/2395882/how-to-remove-extension-from-string-only-real-extension
        $path = preg_replace("/\\.[^.\\s]{3,4}$/", "", $path);

        //print align command
        if(!is_null($align)) {
            switch($align) {
                case "center":
                    $this->_latexcommand('centering', null, 0);
                    break;
                case "left":
                    $this->_latexcommand('raggedleft', null, 0);
                    break;
                case "right":
                    $this->_latexcommand('raggedright', null, 0);
                    break;
                default :
                    break;
            }
        }
        //insert image with params from config.
        $this->_latexcommand('includegraphics', $path, 1, array($this->getConf('image_params')));
    }

    /**
     * Inserts a link to media file other from an image.
     *
     * @param string $path         Relative path to the file.
     * @param string $title        Title of the link.
     * @param string $media_folder Location of media folder.
     */
    protected function _insertFile($path, $title, $media_folder) {
        $path = $media_folder . "/" . $path;
        $this->filelink($path, $title);
    }

    /**
     * General function for inserting links
     *
     * @param string $url           Link URL.
     * @param string $title         Link title.
     * @param string $type          Link type (internal/external/email)
     * @param string $link_original Original link (for internal links it is used as a title)
     */
    protected function _insertLink($url, $title, $type, $link_original = null) {
        $this->_insertLinkPackages();

        if($type == "email") {
            $mailto = "mailto:";
        } else {
            $mailto = "";
        }

        //no title was specified
        if(is_null($title) || (!is_array($title) && trim($title) == '')) {
            //for internal links, original DW link is inserted as a title
            if($type == "internal") {
                $this->doc .= '\\href{' . $mailto . $url . '}{' . $link_original . '}';
            } //email links have to contain mailto and address is used as text
            elseif($type == "email") {
                $this->doc .= '\\href{' . $mailto . $url . '}{' . $url . '}';
            } //reqular external link inserts the whole URL
            else {
                $this->doc .= '\\url{' . $mailto . $url . '}';
            }
        } else {
            //is title an image?
            if(is_array($title)) {
                $this->doc .= '\\href{' . $mailto . $url . '}{';
                if($title["type"] == "internalmedia") {
                    $this->internalmedia($title["src"], $title["title"], $title["align"]);
                } else {
                    $this->externalmedia($title["src"], $title["title"], $title["align"]);
                }
                $this->doc .= '}';
            } else {
                $this->doc .= '\\href{' . $mailto . $url . '}{' . $title . '}';
            }
        }
    }

    /**
     * Escape the text, so it can be used as an pdf string for headers
     *
     * @param string $text
     * @return string
     */
    protected function _pdfString($text) {
        $text = $this->_stripDiacritics($this->_latexSpecialChars($text));
        $text = $this->_removeMathAndSymbols($text);
        return $text;
    }

    /**
     * Removes all math and symbols from the text.
     *
     * @param string $text
     * @return string
     */
    protected function _removeMathAndSymbols($text) {
        $text = preg_replace("#\$(.*)\$#", "", $text);
        //next regex comes from this site:
        //http://stackoverflow.com/questions/5199133/function-to-return-only-alpha-numeric-characters-from-string
        $text = preg_replace("/[^a-zA-Z0-9_ ]+/", "", $text);
        return $text;
    }

    /**
     * Initializes store variable.
     */
    protected function _initializeStore() {
        $this->store = $this->loadHelper('latexit', true);
    }

    /**
     * Function removing diacritcs from a text.
     * From http://cs.wikibooks.org/wiki/PHP_prakticky/Odstran%C4%9Bn%C3%AD_diakritiky
     *
     * @param string $data Text with diacritics
     * @return string Text withou diacritics
     */
    protected function _stripDiacritics($data) {
        $table = Array(
            'ä' => 'a',
            'Ä' => 'A',
            'á' => 'a',
            'Á' => 'A',
            'à' => 'a',
            'À' => 'A',
            'ã' => 'a',
            'Ã' => 'A',
            'â' => 'a',
            'Â' => 'A',
            'č' => 'c',
            'Č' => 'C',
            'ć' => 'c',
            'Ć' => 'C',
            'ď' => 'd',
            'Ď' => 'D',
            'ě' => 'e',
            'Ě' => 'E',
            'é' => 'e',
            'É' => 'E',
            'ë' => 'e',
            'Ë' => 'E',
            'è' => 'e',
            'È' => 'E',
            'ê' => 'e',
            'Ê' => 'E',
            'í' => 'i',
            'Í' => 'I',
            'ï' => 'i',
            'Ï' => 'I',
            'ì' => 'i',
            'Ì' => 'I',
            'î' => 'i',
            'Î' => 'I',
            'ľ' => 'l',
            'Ľ' => 'L',
            'ĺ' => 'l',
            'Ĺ' => 'L',
            'ń' => 'n',
            'Ń' => 'N',
            'ň' => 'n',
            'Ň' => 'N',
            'ñ' => 'n',
            'Ñ' => 'N',
            'ó' => 'o',
            'Ó' => 'O',
            'ö' => 'o',
            'Ö' => 'O',
            'ô' => 'o',
            'Ô' => 'O',
            'ò' => 'o',
            'Ò' => 'O',
            'õ' => 'o',
            'Õ' => 'O',
            'ő' => 'o',
            'Ő' => 'O',
            'ř' => 'r',
            'Ř' => 'R',
            'ŕ' => 'r',
            'Ŕ' => 'R',
            'š' => 's',
            'Š' => 'S',
            'ś' => 's',
            'Ś' => 'S',
            'ť' => 't',
            'Ť' => 'T',
            'ú' => 'u',
            'Ú' => 'U',
            'ů' => 'u',
            'Ů' => 'U',
            'ü' => 'u',
            'Ü' => 'U',
            'ù' => 'u',
            'Ù' => 'U',
            'ũ' => 'u',
            'Ũ' => 'U',
            'û' => 'u',
            'Û' => 'U',
            'ý' => 'y',
            'Ý' => 'Y',
            'ž' => 'z',
            'Ž' => 'Z',
            'ź' => 'z',
            'Ź' => 'Z'
        );

        return strtr($data, $table);
    }

}
