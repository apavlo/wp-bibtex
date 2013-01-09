<?php
/***************************************************************************
 *   Copyright (C) 2011 by Andy Pavlo (pavlo@cs.brown.edu)                 *
 *                                                                         *
 *   Permission is hereby granted, free of charge, to any person obtaining *
 *   a copy of this software and associated documentation files (the       *
 *   "Software"), to deal in the Software without restriction, including   *
 *   without limitation the rights to use, copy, modify, merge, publish,   *
 *   distribute, sublicense, and/or sell copies of the Software, and to    *
 *   permit persons to whom the Software is furnished to do so, subject to *
 *   the following conditions:                                             *
 *                                                                         *
 *   The above copyright notice and this permission notice shall be        *
 *   included in all copies or substantial portions of the Software.       *
 *                                                                         *
 *   THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,       *
 *   EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF    *
 *   MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.*
 *   IN NO EVENT SHALL THE AUTHORS BE LIABLE FOR ANY CLAIM, DAMAGES OR     *
 *   OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, *
 *   ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR *
 *   OTHER DEALINGS IN THE SOFTWARE.                                       *
 ***************************************************************************/

/****************************************************************************
 * Copyright 2006-2009  Sergio Andreozzi  (email : sergio <DOT> andreozzi <AT> gmail <DOT> com)
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program; if not, write to the Free Software
 *   Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 ******************************************************/

/*****************************************************************************
 * Plugin Name: wp-bibtex
 * Plugin URI: https://github.com/apavlo/wp-bibtex
 * Description: wp-bibtex enables to add bibtex entries formatted as HTML in wordpress pages and posts.
 * Version: 1.0
 * Author: Andy Pavlo
 * Author URI: http://www.cs.brown.edu/~pavlo/
 *
 * This plug-in has been improved thanks to the suggestons and contributions of
 *    - Cristiana Bolchini: cleaner bibtex presentation
 *    - Patrick Mau�: remote bibliographies managed by citeulike.org or bibsonomy.org
 *    - Nemo: more characters on key
 *    - Marco Loregian: inverting bibtex and html
 *
 * (2009-04-23) Refactoring by Andy Pavlo http://www.cs.brown.edu/~pavlo/
 *    - Added In-Memory Caching for parsed entries
 *    - Cleaned up code format
 *    - Added Support for Remove Tags
 *    - Better regex for parsing multiple attributes
 *****************************************************************************/

function wpbibtex($myContent) {
    // search for all [bibtex filename] tags and extract the filename
    preg_match_all("/\[[\s]*bibtex[\s]+file=([^\s]+?)((?:[\s]+[\w\d\-]+=[^\s]+)*)]/U", $myContent, $bibItemsSets, PREG_SET_ORDER);

    if ($bibItemsSets) {
        $OSBiBPath = dirname(__FILE__) . '/OSBiB/';
        include_once($OSBiBPath.'format/bibtexParse/PARSEENTRIES.php');
        include_once($OSBiBPath.'format/BIBFORMAT.php');
        include_once(dirname(__FILE__) . '/class.TemplatePower.inc.php');

        /* Format the entries array  for html output */
        $bibformat = NEW BIBFORMAT($OSBiBPath, TRUE); // TRUE implies that the input data is in bibtex format
        $bibformat->cleanEntry=TRUE; // convert BibTeX (and LaTeX) special characters to UTF-8
        list($info, $citation, $styleCommon, $styleTypes) = $bibformat->loadStyle($OSBiBPath."styles/bibliography/", "IEEE");
        $bibformat->getStyle($styleCommon, $styleTypes);
   
        //
        // Process each bibtex tag
        //
        $wpbibtex_cache = Array();
        foreach ($bibItemsSets as $bibItems) {
            // check if bibtex file is URL
            if (strpos($bibItems[1], "http://") !== false) $bibItems[1] = wpbibtex_getCached($bibItems[1]);
            $bibFile = dirname(__FILE__)."/data/".$bibItems[1];
            if (!isset($wpbibtex_cache[$bibFile])) $wpbibtex_cache[$bibFile] = Array();
         
            //
            // Parse Options
            //
            $filterKeys = Array();
            $filterVals = Array();
            $removeTags = Array();
            preg_match_all("/[\s]*([\w\d\-]+)=([^\s]+?)/U", $bibItems[2], $bibOptionSets, PREG_SET_ORDER);
            foreach ($bibOptionSets as $bibOptions) {
                if (strcasecmp($bibOptions[1], "remove-tag") == 0) {
                    $removeTags[] = $bibOptions[2];
                } else {
                    $filterKeys[] = $bibOptions[1];
                $filterVals[] = $bibOptions[2];
                }
            } // FOREACH
            //
            // For now we only support single keys
            //
            if (count($filterKeys) > 1) {
                $myContent = str_replace( $bibItems[0], $bibItems[1] . ' Currently only support filtering on single attribute', $myContent);
                continue;
            }
            $filterKey = $filterKeys[0];
            $filterVal = $filterVals[0];

            //
            // Check whether we have already parsed this file and we have the entries in our cache
            //
            if (!isset($wpbibtex_cache[$bibFile][$filterKey])) {
                //
                // Load BibTex File
                //
                if (file_exists($bibFile)) {
                    $bib = file_get_contents($bibFile);
                    //
                    // We have entries, so we need to parse them and then organize them based on filterKey
                    //
                    if (!empty($bib)) {
                        $entries = wpbibtex_process($bib, $bibformat);
                        $wpbibtex_cache[$bibFile][$filterKey] = wpbibtex_filter($entries, $filterKey);
                        //echo "<pre>\n"; var_dump($wpbibtex_cache[$bibFile]); echo "</pre>";
                    } else { 
                        $myContent = str_replace( $bibItems[0], $bibItems[1] . ' bibtex file empty', $myContent);
                    }
                //
                // Error: File Does Not Exist
                //
                } else {
                    $myContent = str_replace( $bibItems[0], $bibItems[1] . ' bibtex file not found', $myContent);
                }
            }
            //
            // Now pull the entries we want from the cache and display them to the mother trucker!
            //
            if (isset($wpbibtex_cache[$bibFile])) {
                // Support multiple filter keys separated by commas
                $bibtexContent = Array();
                $allKeys = explode(",", $filterVal);
                foreach ($allKeys as $key) {
                    $key = trim($key);
                    if (isset($wpbib2html_cache[$bibFile][$filterKey][$key])) {
                        $bibtexContent = array_merge($bibtexContent, $wpbib2html_cache[$bibFile][$filterKey][$key]);
                    }
                } // FOREACH
            
                // echo "<pre>\n"; var_dump($bibtexContent); echo "</pre>";
                $htmlbib = wpbibtex_display($bibtexContent, $bibformat, $removeTags);
                $myContent = str_replace($bibItems[0], $htmlbib, $myContent);
            }
        } // FOREACH
    }
    return $myContent;
}

function wpbibtex_sortByYear($a, $b) {
    $f1 = $a['year']; 
    $f2 = $b['year']; 
    if ($f1 == $f2) return 0;
    return ($f1 < $f2) ? -1 : 1;
}

function wpbibtex_process($data, $bibformat) {
    // parse the content of bib string and generate associative array with valid entries
    $parse = NEW PARSEENTRIES();
    $parse->expandMacro = TRUE;
    $parse->fieldExtract = TRUE;
    $parse->removeDelimit = TRUE;
    $parse->loadBibtexString($data);
    $parse->extractEntries();
    list($preamble, $strings, $entries) = $parse->returnArrays();
   
    // currently sorting descending on year by default
    usort($entries, "wpbibtex_sortByYear");
    $reverse=true;
    if ($reverse) {
        $entries = array_reverse($entries);
    }
    return $entries;
}

function wpbibtex_filter($entries, $filterKey) {
    $ret = Array();
   
    //
    // Backwards Compatible: allow + deny
    //
    $filter_allow = (strcasecmp($filterKey, "allow") === 0);
    $filter_deny  = (strcasecmp($filterKey, "deny") === 0);
    if ($filter_allow || $filter_deny) {
        //
        // First organize all the entries by their EntryType
        //
        $ret = Array();
        foreach ($entries as $entry) {
            $ret[$entry['bibtexEntryType']][] = $entry;
        } // FOREACH
      
        //
        // If it's an allow, then we don't have to do anything
        // But if it's a deny, then our list explodes!
        //
        if ($filter_deny) {
            $temp = $ret;
            foreach ($temp as $resourceType => $entries) {
                $ret[$resourceType] = Array();
                foreach ($temp as $resourceType2 => $entries2) {
                    if ($resourceType == $resourceType2) continue;
                    $ret[$resourceType] += $temp[$resourceType2];
                } // FOREACH
            } // FOREACH
        }
    //
    // New Version: Filter by BibTex attribute
    //
    } else {
        if (strcasecmp($filterKey, 'key') === 0) $filterKey = 'bibtexCitation';
        foreach ($entries as $entry) {
            $value = $entry[$filterKey];
            if (!isset($ret[$value])) {
                $ret[$value] = Array();
            }
            $ret[$value][] = $entry;
        } // FOREACH
    }
    return ($ret);
}

function wpbibtex_display($entries, $bibformat, $removeTags) {
    $tpl = new TemplatePower(dirname(__FILE__) . '/bibentry-html.tpl');
    $tpl->prepare();
   
    if (count($removeTags)) {
        $removeRegex = implode("|", array_map("preg_quote", $removeTags));
    }
   
    if (isset($entries) && count($entries)) {
        foreach ($entries as $entry) {
            // Get the resource type ('book', 'article', 'inbook' etc.)
            $resourceType = $entry['bibtexEntryType'];
            //  adds all the resource elements automatically to the BIBFORMAT::item array
            $bibformat->preProcess($resourceType, $entry);
      
            // get the formatted resource string ready for printing to the web browser
            // the str_replace is used to remove the { } parentheses possibly present in title 
            // to enforce uppercase, TODO: check if it can be done only on title 
            $tpl->newBlock("bibtex_entry");
            $tpl->assign("year", $entry['year']);
            $tpl->assign("type", $entry['bibtexEntryType']);
            // 20110125: hkimura. to show advisors in theses list.
            if (array_key_exists('advisor', $entry)) {
                $tpl->assign("advisor", "<p class='advisor'>Advised by: " . $entry['advisor'] . "</p>");
            } else {
                $tpl->assign("advisor", "");
            }
            $tpl->assign("pdf", wpbibtex_downloadLink($entry));
            $tpl->assign("key", strtr($entry['bibtexCitation'], ":", "-"));
            $tpl->assign("entry", str_replace(array('{', '}'), '', $bibformat->map()));
            $tpl->assign("bibtex", wpbibtex_format($entry['bibtexEntry'], $removeRegex));
        } // FOREACH
    }
    return $tpl->getOutputContent();
}

// this function formats a bibtex code in order to be readable
// when appearing in the modal window
function wpbibtex_format($entry, $removeRegex){
    static $order = array("},");
    static $replace = "}, <br />\n &nbsp;";
    $new_entry = preg_replace('/\s\s+/', ' ', trim($entry));
    $new_entry = preg_replace('/\n\s+\}$/', '}', trim($entry));
   
    // Remove unwanted attributes
    if (isset($removeRegex)) {
        $new_entry = preg_replace("/[\s]*($removeRegex)[\s]*=[\s]*\{.*?\}[,]?[\s\n]*/", "", $new_entry);
    }
   
    $new_entry = str_replace($order, $replace, $new_entry);
    $new_entry = preg_replace("/^(\@[\w]+\{.*?)[\s]*,[\s]+([\w\d]+)[\s]*=/i", "\\1, <br />\n &nbsp;&nbsp;\\2 = ", $new_entry);
    $new_entry = preg_replace('/\},?\s*\}$/', "}\n}", $new_entry); 
    return $new_entry;
}

function wpbibtex_downloadLink($entry) {
    if (array_key_exists('url',$entry)){
        $string = " <a href='" . $entry['url'] . "' title='Download Document' class='bibtex'>[PDF]</a>";
        return $string;
    }
    return '';
}

/* Returns filename of cached version of given url  */
function wpbibtex_getCached($url) {
    // check if cached file exists
    $name = substr($url, strrpos($url, "/")+1);
    $file = dirname(__FILE__) . "/data/" . $name . ".cached.bib";
    
    // check if file date exceeds 60 minutes   
    if (! (file_exists($file) && (filemtime($file) + 3600 > time())))  {
        // not returned yet, grab new version
        $f=fopen($file,"wb");
        if ($f) {
                  fwrite($f,file_get_contents($url));
                  fclose($f);
        } else echo "Failed to write file" . $file . " - check directory permission according to your Web server privileges.";
    }
   
    return $name.".cached.bib";
}


function wpbibtex_head() {
    if (!function_exists('wp_enqueue_script')) {
        echo "\n" . '<script src="'.  get_bloginfo('wpurl') . '/wp-content/plugins/wp-bibtex/js/jquery.js"  type="text/javascript"></script>' . "\n";
        echo '<script src="'.  get_bloginfo('wpurl') . '/wp-content/plugins/wp-bibtex/js/bib2html.js"  type="text/javascript"></script>' . "\n";
    }
    echo "<style type=\"text/css\">\n".
         "div.bibtex { display: none; }\n".
         "</style>";
}

function wpbibtex_init() {
    if (function_exists('wp_enqueue_script')) {
        wp_register_script('wp-bibtex', get_bloginfo('wpurl') . '/wp-content/plugins/wp-bibtex/js/wp-bibtex.js', array('jquery'), '0.7');
        wp_enqueue_script('wp-bibtex');
    } 
}

add_action('init', 'wpbibtex_init');   
add_action('wp_head', 'wpbibtex_head');
add_filter('the_content', 'wpbibtex',1);

?>
