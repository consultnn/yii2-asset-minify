<?php
/*!
 * cssmin.php v2.4.8-4
 * Author: Tubal Martin - http://tubalmartin.me/
 * Repo: https://github.com/tubalmartin/YUI-CSS-compressor-PHP-port
 *
 * This is a PHP port of the CSS minification tool distributed with YUICompressor,
 * itself a port of the cssmin utility by Isaac Schlueter - http://foohack.com/
 * Permission is hereby granted to use the PHP version under the same
 * conditions as the YUICompressor.
 */

namespace consultnn\minify;

/*!
 * YUI Compressor
 * http://developer.yahoo.com/yui/compressor/
 * Author: Julien Lecomte - http://www.julienlecomte.net/
 * Copyright (c) 2013 Yahoo! Inc. All rights reserved.
 * The copyrights embodied in the content of this file are licensed
 * by Yahoo! Inc. under the BSD (revised) open source license.
 */

class Css
{
    const NL = '___YUICSSMIN_PRESERVED_NL___';
    const TOKEN = '___YUICSSMIN_PRESERVED_TOKEN_';
    const COMMENT = '___YUICSSMIN_PRESERVE_CANDIDATE_COMMENT_';
    const CLASS_COLON = '___YUICSSMIN_PSEUDOCLASSCOLON___';
    const QUERY_FRACTION = '___YUICSSMIN_QUERY_FRACTION___';

    private $_comments;
    private $_preserved_tokens;
    private $_memory_limit;
    private $_max_execution_time;
    private $_pcre_backtrack_limit;
    private $_pcre_recursion_limit;
    private $_raise_php_limits;

    /**
     * @param bool|int $raise_php_limits
     * If true, PHP settings will be raised if needed
     */
    public function __construct($raise_php_limits = true)
    {
        // Set suggested PHP limits
        $this->_memory_limit = 128 * 1048576; // 128MB in bytes
        $this->_max_execution_time = 60; // 1 min
        $this->_pcre_backtrack_limit = 1000 * 1000;
        $this->_pcre_recursion_limit =  500 * 1000;

        $this->_raise_php_limits = (bool) $raise_php_limits;
    }

    /**
     * Minify a string of CSS
     * @param string $css
     * @param int|bool $linebreak_pos
     * @return string
     */
    public function run($css = '', $linebreak_pos = false)
    {
        if (empty($css)) {
            return '';
        }

        if ($this->_raise_php_limits) {
            $this->doRaisePhpLimits();
        }

        $this->_comments = [];
        $this->_preserved_tokens = [];

        $start_index = 0;
        $length = strlen($css);

        $css = $this->extractDataUrls($css);

        // collect all comment blocks...
        while (($start_index = $this->indexOf($css, '/*', $start_index)) >= 0) {
            $end_index = $this->indexOf($css, '*/', $start_index + 2);
            if ($end_index < 0) {
                $end_index = $length;
            }
            $comment_found = $this->strSlice($css, $start_index + 2, $end_index);
            $this->_comments[] = $comment_found;
            $comment_preserve_string = self::COMMENT . (count($this->_comments) - 1) . '___';
            $css = $this->strSlice($css, 0, $start_index + 2) . $comment_preserve_string . $this->strSlice($css, $end_index);
            // Set correct start_index: Fixes issue #2528130
            $start_index = $end_index + 2 + strlen($comment_preserve_string) - strlen($comment_found);
        }

        // preserve strings so their content doesn't get accidentally minified
        $css = preg_replace_callback('/(?:"(?:[^\\\\"]|\\\\.|\\\\)*")|'."(?:'(?:[^\\\\']|\\\\.|\\\\)*')/S", [$this,
                'replaceString'
            ], $css);

        // Let's divide css code in chunks of 5.000 chars aprox.
        // Reason: PHP's PCRE functions like preg_replace have a "backtrack limit"
        // of 100.000 chars by default (php < 5.3.7) so if we're dealing with really
        // long strings and a (sub)pattern matches a number of chars greater than
        // the backtrack limit number (i.e. /(.*)/s) PCRE functions may fail silently
        // returning NULL and $css would be empty.
        $charset = '';
        $charset_regexp = '/(@charset)( [^;]+;)/i';
        $css_chunks = [];
        $css_chunk_length = 5000; // aprox size, not exact
        $start_index = 0;
        $i = $css_chunk_length; // save initial iterations
        $l = strlen($css);


        // if the number of characters is 5000 or less, do not chunk
        if ($l <= $css_chunk_length) {
            $css_chunks[] = $css;
        } else {
            // chunk css code securely
            while ($i < $l) {
                $i += 50; // save iterations
                if ($l - $start_index <= $css_chunk_length || $i >= $l) {
                    $css_chunks[] = $this->strSlice($css, $start_index);
                    break;
                }
                if ($css[$i - 1] === '}' && $i - $start_index > $css_chunk_length) {
                    // If there are two ending curly braces }} separated or not by spaces,
                    // join them in the same chunk (i.e. @media blocks)
                    $next_chunk = substr($css, $i);
                    if (preg_match('/^\s*\}/', $next_chunk)) {
                        $i = $i + $this->indexOf($next_chunk, '}') + 1;
                    }

                    $css_chunks[] = $this->strSlice($css, $start_index, $i);
                    $start_index = $i;
                }
            }
        }

        // Minify each chunk
        for ($i = 0, $n = count($css_chunks); $i < $n; $i++) {
            $css_chunks[$i] = $this->minify($css_chunks[$i], $linebreak_pos);
            // Keep the first @charset at-rule found
            if (empty($charset) && preg_match($charset_regexp, $css_chunks[$i], $matches)) {
                $charset = strtolower($matches[1]) . $matches[2];
            }
            // Delete all @charset at-rules
            $css_chunks[$i] = preg_replace($charset_regexp, '', $css_chunks[$i]);
        }

        // Update the first chunk and push the charset to the top of the file.
        $css_chunks[0] = $charset . $css_chunks[0];

        return implode('', $css_chunks);
    }

    /**
     * Sets the memory limit for this script
     * @param int|string $limit
     */
    public function setMemoryLimit($limit)
    {
        $this->_memory_limit = $this->normalizeInt($limit);
    }

    /**
     * Sets the maximum execution time for this script
     * @param int|string $seconds
     */
    public function setMaxExecutionTime($seconds)
    {
        $this->_max_execution_time = (int) $seconds;
    }

    /**
     * Sets the PCRE backtrack limit for this script
     * @param int $limit
     */
    public function setPcreBacktrackLimit($limit)
    {
        $this->_pcre_backtrack_limit = (int) $limit;
    }

    /**
     * Sets the PCRE recursion limit for this script
     * @param int $limit
     */
    public function setPcreRecursionLimit($limit)
    {
        $this->_pcre_recursion_limit = (int) $limit;
    }

    /**
     * Try to configure PHP to use at least the suggested minimum settings
     */
    private function doRaisePhpLimits()
    {
        $php_limits = [
            'memory_limit' => $this->_memory_limit,
            'max_execution_time' => $this->_max_execution_time,
            'pcre.backtrack_limit' => $this->_pcre_backtrack_limit,
            'pcre.recursion_limit' =>  $this->_pcre_recursion_limit
        ];

        // If current settings are higher respect them.
        foreach ($php_limits as $name => $suggested) {
            $current = $this->normalizeInt(ini_get($name));
            // memory_limit exception: allow -1 for "no memory limit".
            if ($current > -1 && ($suggested == -1 || $current < $suggested)) {
                ini_set($name, $suggested);
            }
        }
    }

    /**
     * Does bulk of the minification
     * @param string $css
     * @param int|bool $linebreak_pos
     * @return string
     */
    private function minify($css, $linebreak_pos)
    {
        // strings are safe, now wrestle the comments
        for ($i = 0, $max = count($this->_comments); $i < $max; $i++) {

            $token = $this->_comments[$i];
            $placeholder = '/' . self::COMMENT . $i . '___/';

            // ! in the first position of the comment means preserve
            // so push to the preserved tokens keeping the !
            if (substr($token, 0, 1) === '!') {
                $this->_preserved_tokens[] = $token;
                $token_tring = self::TOKEN . (count($this->_preserved_tokens) - 1) . '___';
                $css = preg_replace($placeholder, $token_tring, $css, 1);
                // Preserve new lines for /*! important comments
                $css = preg_replace('/\s*[\n\r\f]+\s*(\/\*'. $token_tring .')/S', self::NL.'$1', $css);
                $css = preg_replace('/('. $token_tring .'\*\/)\s*[\n\r\f]+\s*/', '$1'.self::NL, $css);
                continue;
            }

            // \ in the last position looks like hack for Mac/IE5
            // shorten that to /*\*/ and the next one to /**/
            if (substr($token, (strlen($token) - 1), 1) === '\\') {
                $this->_preserved_tokens[] = '\\';
                $css = preg_replace($placeholder, self::TOKEN . (count($this->_preserved_tokens) - 1) . '___', $css, 1);
                $i = $i + 1; // attn: advancing the loop
                $this->_preserved_tokens[] = '';
                $css = preg_replace('/' . self::COMMENT . $i . '___/', self::TOKEN . (count($this->_preserved_tokens) - 1) . '___', $css, 1);
                continue;
            }

            // keep empty comments after child selectors (IE7 hack)
            // e.g. html >/**/ body
            if (strlen($token) === 0) {
                $start_index = $this->indexOf($css, $this->strSlice($placeholder, 1, -1));
                if ($start_index > 2) {
                    if (substr($css, $start_index - 3, 1) === '>') {
                        $this->_preserved_tokens[] = '';
                        $css = preg_replace($placeholder, self::TOKEN . (count($this->_preserved_tokens) - 1) . '___', $css, 1);
                    }
                }
            }

            // in all other cases kill the comment
            $css = preg_replace('/\/\*' . $this->strSlice($placeholder, 1, -1) . '\*\//', '', $css, 1);
        }


        // Normalize all whitespace strings to single spaces. Easier to work with that way.
        $css = preg_replace('/\s+/', ' ', $css);

        // Fix IE7 issue on matrix filters which browser accept whitespaces between Matrix parameters
        $css = preg_replace_callback('/\s*filter\:\s*progid:DXImageTransform\.Microsoft\.Matrix\(([^\)]+)\)/', [$this,
                'preserveOldIESpecificMatrixDefinition'
            ], $css);

        // Shorten & preserve calculations calc(...) since spaces are important
        $css = preg_replace_callback('/calc(\(((?:[^\(\)]+|(?1))*)\))/i', [$this, 'replaceCalc'], $css);

        // Replace positive sign from numbers preceded by : or a white-space before the leading space is removed
        // +1.2em to 1.2em, +.8px to .8px, +2% to 2%
        $css = preg_replace('/((?<!\\\\)\:|\s)\+(\.?\d+)/S', '$1$2', $css);

        // Remove leading zeros from integer and float numbers preceded by : or a white-space
        // 000.6 to .6, -0.8 to -.8, 0050 to 50, -01.05 to -1.05
        $css = preg_replace('/((?<!\\\\)\:|\s)(\-?)0+(\.?\d+)/S', '$1$2$3', $css);

        // Remove trailing zeros from float numbers preceded by : or a white-space
        // -6.0100em to -6.01em, .0100 to .01, 1.200px to 1.2px
        $css = preg_replace('/((?<!\\\\)\:|\s)(\-?)(\d?\.\d+?)0+([^\d])/S', '$1$2$3$4', $css);

        // Remove trailing .0 -> -9.0 to -9
        $css = preg_replace('/((?<!\\\\)\:|\s)(\-?\d+)\.0([^\d])/S', '$1$2$3', $css);

        // Replace 0 length numbers with 0
        $css = preg_replace('/((?<!\\\\)\:|\s)\-?\.?0+([^\d])/S', '${1}0$2', $css);

        // Remove the spaces before the things that should not have spaces before them.
        // But, be careful not to turn "p :link {...}" into "p:link{...}"
        // Swap out any pseudo-class colons with the token, and then swap back.
        $css = preg_replace_callback('/(?:^|\})[^\{]*\s+\:/', [$this, 'replaceColon'], $css);

        // Remove spaces before the things that should not have spaces before them.
        $css = preg_replace('/\s+([\!\{\}\;\:\>\+\(\)\]\~\=,])/', '$1', $css);

        // Restore spaces for !important
        $css = preg_replace('/\!important/i', ' !important', $css);

        // bring back the colon
        $css = preg_replace('/' . self::CLASS_COLON . '/', ':', $css);

        // retain space for special IE6 cases
        $css = preg_replace_callback('/\:first\-(line|letter)(\{|,)/i', [$this, 'lowercasePseudoFirst'], $css);

        // no space after the end of a preserved comment
        $css = preg_replace('/\*\/ /', '*/', $css);

        // lowercase some popular @directives
        $css = preg_replace_callback('/@(font-face|import|(?:-(?:atsc|khtml|moz|ms|o|wap|webkit)-)?keyframe|media|page|namespace)/i', [$this,
                'lowercaseDirectives'
            ], $css);

        // lowercase some more common pseudo-elements
        $css = preg_replace_callback('/:(active|after|before|checked|disabled|empty|enabled|first-(?:child|of-type)|focus|hover|last-(?:child|of-type)|link|only-(?:child|of-type)|root|:selection|target|visited)/i', [$this,
                'lowercasePseudoElements'
            ], $css);

        // lowercase some more common functions
        $css = preg_replace_callback('/:(lang|not|nth-child|nth-last-child|nth-last-of-type|nth-of-type|(?:-(?:moz|webkit)-)?any)\(/i', [$this,
                'lowercaseCommonFunctions'
            ], $css);

        // lower case some common function that can be values
        // NOTE: rgb() isn't useful as we replace with #hex later, as well as and() is already done for us
        $css = preg_replace_callback('/([:,\( ]\s*)(attr|color-stop|from|rgba|to|url|(?:-(?:atsc|khtml|moz|ms|o|wap|webkit)-)?(?:calc|max|min|(?:repeating-)?(?:linear|radial)-gradient)|-webkit-gradient)/iS', [$this,
                'lowercaseCommonFunctionsValues'
            ], $css);

        // Put the space back in some cases, to support stuff like
        // @media screen and (-webkit-min-device-pixel-ratio:0){
        $css = preg_replace('/\band\(/i', 'and (', $css);

        // Remove the spaces after the things that should not have spaces after them.
        $css = preg_replace('/([\!\{\}\:;\>\+\(\[\~\=,])\s+/S', '$1', $css);

        // remove unnecessary semicolons
        $css = preg_replace('/;+\}/', '}', $css);

        // Fix for issue: #2528146
        // Restore semicolon if the last property is prefixed with a `*` (lte IE7 hack)
        // to avoid issues on Symbian S60 3.x browsers.
        $css = preg_replace('/(\*[a-z0-9\-]+\s*\:[^;\}]+)(\})/', '$1;$2', $css);

        // Replace 0 <length> and 0 <percentage> values with 0.
        // <length> data type: https://developer.mozilla.org/en-US/docs/Web/CSS/length
        // <percentage> data type: https://developer.mozilla.org/en-US/docs/Web/CSS/percentage
        $css = preg_replace('/([^\\\\]\:|\s)0(?:em|ex|ch|rem|vw|vh|vm|vmin|cm|mm|in|px|pt|pc|%)/iS', '${1}0', $css);

        // 0% step in a keyframe? restore the % unit
        $css = preg_replace_callback('/(@[a-z\-]*?keyframes[^\{]+\{)(.*?)(\}\})/iS', [$this, 'replaceKeyframeZero'], $css);

        // Replace 0 0; or 0 0 0; or 0 0 0 0; with 0.
        $css = preg_replace('/\:0(?: 0){1,3}(;|\}| \!)/', ':0$1', $css);

        // Fix for issue: #2528142
        // Replace text-shadow:0; with text-shadow:0 0 0;
        $css = preg_replace('/(text-shadow\:0)(;|\}| \!)/i', '$1 0 0$2', $css);

        // Replace background-position:0; with background-position:0 0;
        // same for transform-origin
        // Changing -webkit-mask-position: 0 0 to just a single 0 will result in the second parameter defaulting to 50% (center)
        $css = preg_replace('/(background\-position|webkit-mask-position|(?:webkit|moz|o|ms|)\-?transform\-origin)\:0(;|\}| \!)/iS', '$1:0 0$2', $css);

        // Shorten colors from rgb(51,102,153) to #336699, rgb(100%,0%,0%) to #ff0000 (sRGB color space)
        // Shorten colors from hsl(0, 100%, 50%) to #ff0000 (sRGB color space)
        // This makes it more likely that it'll get further compressed in the next step.
        $css = preg_replace_callback('/rgb\s*\(\s*([0-9,\s\-\.\%]+)\s*\)(.{1})/i', [$this, 'rgbToHex'], $css);
        $css = preg_replace_callback('/hsl\s*\(\s*([0-9,\s\-\.\%]+)\s*\)(.{1})/i', [$this, 'hslToHex'], $css);

        // Shorten colors from #AABBCC to #ABC or short color name.
        $css = $this->compressHexColors($css);

        // border: none to border:0, outline: none to outline:0
        $css = preg_replace('/(border\-?(?:top|right|bottom|left|)|outline)\:none(;|\}| \!)/iS', '$1:0$2', $css);

        // shorter opacity IE filter
        $css = preg_replace('/progid\:DXImageTransform\.Microsoft\.Alpha\(Opacity\=/i', 'alpha(opacity=', $css);

        // Find a fraction that is used for Opera's -o-device-pixel-ratio query
        // Add token to add the "\" back in later
        $css = preg_replace('/\(([a-z\-]+):([0-9]+)\/([0-9]+)\)/i', '($1:$2'. self::QUERY_FRACTION .'$3)', $css);

        // Remove empty rules.
        $css = preg_replace('/[^\};\{\/]+\{\}/S', '', $css);

        // Add "/" back to fix Opera -o-device-pixel-ratio query
        $css = preg_replace('/'. self::QUERY_FRACTION .'/', '/', $css);

        // Replace multiple semi-colons in a row by a single one
        // See SF bug #1980989
        $css = preg_replace('/;;+/', ';', $css);

        // Restore new lines for /*! important comments
        $css = preg_replace('/'. self::NL .'/', "\n", $css);

        // Lowercase all uppercase properties
        $css = preg_replace_callback('/(\{|\;)([A-Z\-]+)(\:)/', [$this, 'lowercaseProperties'], $css);

        // Some source control tools don't like it when files containing lines longer
        // than, say 8000 characters, are checked in. The linebreak option is used in
        // that case to split long lines after a specific column.
        if ($linebreak_pos !== false && (int) $linebreak_pos >= 0) {
            $linebreak_pos = (int) $linebreak_pos;
            $start_index = $i = 0;
            while ($i < strlen($css)) {
                $i++;
                if ($css[$i - 1] === '}' && $i - $start_index > $linebreak_pos) {
                    $css = $this->strSlice($css, 0, $i) . "\n" . $this->strSlice($css, $i);
                    $start_index = $i;
                }
            }
        }

        // restore preserved comments and strings in reverse order
        for ($i = count($this->_preserved_tokens) - 1; $i >= 0; $i--) {
            $css = preg_replace('/' . self::TOKEN . $i . '___/', $this->_preserved_tokens[$i], $css, 1);
        }

        // Trim the final string (for any leading or trailing white spaces)
        return trim($css);
    }

    /**
     * Utility method to replace all data urls with tokens before we start
     * compressing, to avoid performance issues running some of the subsequent
     * regexes against large strings chunks.
     *
     * @param string $css
     * @return string
     */
    private function extractDataUrls($css)
    {
        // Leave data urls alone to increase parse performance.
        $max_index = strlen($css) - 1;
        $append_index = $index = $last_index = $offset = 0;
        $sb = [];
        $pattern = '/url\(\s*(["\']?)data\:/i';

        // Since we need to account for non-base64 data urls, we need to handle
        // ' and ) being part of the data string. Hence switching to indexOf,
        // to determine whether or not we have matching string terminators and
        // handling sb appends directly, instead of using matcher.append* methods.

        while (preg_match($pattern, $css, $m, 0, $offset)) {
            $index = $this->indexOf($css, $m[0], $offset);
            $last_index = $index + strlen($m[0]);
            $start_index = $index + 4; // "url(".length()
            $end_index = $last_index - 1;
            $terminator = $m[1]; // ', " or empty (not quoted)
            $found_terminator = false;

            if (strlen($terminator) === 0) {
                $terminator = ')';
            }

            while ($found_terminator === false && $end_index+1 <= $max_index) {
                $end_index = $this->indexOf($css, $terminator, $end_index + 1);

                // endIndex == 0 doesn't really apply here
                if ($end_index > 0 && substr($css, $end_index - 1, 1) !== '\\') {
                    $found_terminator = true;
                    if (')' != $terminator) {
                        $end_index = $this->indexOf($css, ')', $end_index);
                    }
                }
            }

            // Enough searching, start moving stuff over to the buffer
            $sb[] = $this->strSlice($css, $append_index, $index);

            if ($found_terminator) {
                $token = $this->strSlice($css, $start_index, $end_index);
                $token = preg_replace('/\s+/', '', $token);
                $this->_preserved_tokens[] = $token;

                $preserver = 'url(' . self::TOKEN . (count($this->_preserved_tokens) - 1) . '___)';
                $sb[] = $preserver;

                $append_index = $end_index + 1;
            } else {
                // No end terminator found, re-add the whole match. Should we throw/warn here?
                $sb[] = $this->strSlice($css, $index, $last_index);
                $append_index = $last_index;
            }

            $offset = $last_index;
        }

        $sb[] = $this->strSlice($css, $append_index);

        return implode('', $sb);
    }

    /**
     * Utility method to compress hex color values of the form #AABBCC to #ABC or short color name.
     *
     * DOES NOT compress CSS ID selectors which match the above pattern (which would break things).
     * e.g. #AddressForm { ... }
     *
     * DOES NOT compress IE filters, which have hex color values (which would break things).
     * e.g. filter: chroma(color="#FFFFFF");
     *
     * DOES NOT compress invalid hex values.
     * e.g. background-color: #aabbccdd
     *
     * @param string $css
     * @return string
     */
    private function compressHexColors($css)
    {
        // Look for hex colors inside { ... } (to avoid IDs) and which don't have a =, or a " in front of them (to avoid filters)
        $pattern = '/(\=\s*?["\']?)?#([0-9a-f])([0-9a-f])([0-9a-f])([0-9a-f])([0-9a-f])([0-9a-f])(\}|[^0-9a-f{][^{]*?\})/iS';
        $_index = $index = $last_index = $offset = 0;
        $sb = [];
        // See: http://ajaxmin.codeplex.com/wikipage?title=CSS%20Colors
        $short_safe = [
            '#808080' => 'gray',
            '#008000' => 'green',
            '#800000' => 'maroon',
            '#000080' => 'navy',
            '#808000' => 'olive',
            '#ffa500' => 'orange',
            '#800080' => 'purple',
            '#c0c0c0' => 'silver',
            '#008080' => 'teal',
            '#f00' => 'red'
        ];

        while (preg_match($pattern, $css, $m, 0, $offset)) {
            $index = $this->indexOf($css, $m[0], $offset);
            $last_index = $index + strlen($m[0]);
            $is_filter = $m[1] !== null && $m[1] !== '';

            $sb[] = $this->strSlice($css, $_index, $index);

            if ($is_filter) {
                // Restore, maintain case, otherwise filter will break
                $sb[] = $m[1] . '#' . $m[2] . $m[3] . $m[4] . $m[5] . $m[6] . $m[7];
            } else {
                if (strtolower($m[2]) == strtolower($m[3]) &&
                    strtolower($m[4]) == strtolower($m[5]) &&
                    strtolower($m[6]) == strtolower($m[7])) {
                    // Compress.
                    $hex = '#' . strtolower($m[3] . $m[5] . $m[7]);
                } else {
                    // Non compressible color, restore but lower case.
                    $hex = '#' . strtolower($m[2] . $m[3] . $m[4] . $m[5] . $m[6] . $m[7]);
                }
                // replace Hex colors to short safe color names
                $sb[] = array_key_exists($hex, $short_safe) ? $short_safe[$hex] : $hex;
            }

            $_index = $offset = $last_index - strlen($m[8]);
        }

        $sb[] = $this->strSlice($css, $_index);

        return implode('', $sb);
    }

    /* CALLBACKS
     * ---------------------------------------------------------------------------------------------
     */

    private function replaceString($matches)
    {
        $match = $matches[0];
        $quote = substr($match, 0, 1);
        // Must use addcslashes in PHP to avoid parsing of backslashes
        $match = addcslashes($this->strSlice($match, 1, -1), '\\');

        // maybe the string contains a comment-like substring?
        // one, maybe more? put'em back then
        if (($pos = $this->indexOf($match, self::COMMENT)) >= 0) {
            for ($i = 0, $max = count($this->_comments); $i < $max; $i++) {
                $match = preg_replace('/' . self::COMMENT . $i . '___/', $this->_comments[$i], $match, 1);
            }
        }

        // minify alpha opacity in filter strings
        $match = preg_replace('/progid\:DXImageTransform\.Microsoft\.Alpha\(Opacity\=/i', 'alpha(opacity=', $match);

        $this->_preserved_tokens[] = $match;
        return $quote . self::TOKEN . (count($this->_preserved_tokens) - 1) . '___' . $quote;
    }

    private function replaceColon($matches)
    {
        return preg_replace('/\:/', self::CLASS_COLON, $matches[0]);
    }

    private function replaceCalc($matches)
    {
        $this->_preserved_tokens[] = trim(preg_replace('/\s*([\*\/\(\),])\s*/', '$1', $matches[2]));
        return 'calc('. self::TOKEN . (count($this->_preserved_tokens) - 1) . '___' . ')';
    }

    private function preserveOldIESpecificMatrixDefinition($matches)
    {
        $this->_preserved_tokens[] = $matches[1];
        return 'filter:progid:DXImageTransform.Microsoft.Matrix(' . self::TOKEN . (count($this->_preserved_tokens) - 1) . '___' . ')';
    }

    private function replaceKeyframeZero($matches)
    {
        return $matches[1] . preg_replace('/0(\{|,[^\)\{]+\{)/', '0%$1', $matches[2]) . $matches[3];
    }

    private function rgbToHex($matches)
    {
        // Support for percentage values rgb(100%, 0%, 45%);
        if ($this->indexOf($matches[1], '%') >= 0) {
            $rgbcolors = explode(',', str_replace('%', '', $matches[1]));
            for ($i = 0; $i < count($rgbcolors); $i++) {
                $rgbcolors[$i] = $this->roundNumber(floatval($rgbcolors[$i]) * 2.55);
            }
        } else {
            $rgbcolors = explode(',', $matches[1]);
        }

        // Values outside the sRGB color space should be clipped (0-255)
        for ($i = 0; $i < count($rgbcolors); $i++) {
            $rgbcolors[$i] = $this->clampNumber(intval($rgbcolors[$i], 10), 0, 255);
            $rgbcolors[$i] = sprintf("%02x", $rgbcolors[$i]);
        }

        // Fix for issue #2528093
        if (!preg_match('/[\s\,\);\}]/', $matches[2])) {
            $matches[2] = ' ' . $matches[2];
        }

        return '#' . implode('', $rgbcolors) . $matches[2];
    }

    private function hslToHex($matches)
    {
        $values = explode(',', str_replace('%', '', $matches[1]));
        $h = floatval($values[0]);
        $s = floatval($values[1]);
        $l = floatval($values[2]);

        // Wrap and clamp, then fraction!
        $h = ((($h % 360) + 360) % 360) / 360;
        $s = $this->clampNumber($s, 0, 100) / 100;
        $l = $this->clampNumber($l, 0, 100) / 100;

        if ($s == 0) {
            $r = $g = $b = $this->roundNumber(255 * $l);
        } else {
            $v2 = $l < 0.5 ? $l * (1 + $s) : ($l + $s) - ($s * $l);
            $v1 = (2 * $l) - $v2;
            $r = $this->roundNumber(255 * $this->hueToRgb($v1, $v2, $h + (1/3)));
            $g = $this->roundNumber(255 * $this->hueToRgb($v1, $v2, $h));
            $b = $this->roundNumber(255 * $this->hueToRgb($v1, $v2, $h - (1/3)));
        }

        return $this->rgbToHex(['', $r.','.$g.','.$b, $matches[2]]);
    }

    private function lowercasePseudoFirst($matches)
    {
        return ':first-'. strtolower($matches[1]) .' '. $matches[2];
    }

    private function lowercaseDirectives($matches)
    {
        return '@'. strtolower($matches[1]);
    }

    private function lowercasePseudoElements($matches)
    {
        return ':'. strtolower($matches[1]);
    }

    private function lowercaseCommonFunctions($matches)
    {
        return ':'. strtolower($matches[1]) .'(';
    }

    private function lowercaseCommonFunctionsValues($matches)
    {
        return $matches[1] . strtolower($matches[2]);
    }

    private function lowercaseProperties($matches)
    {
        return $matches[1].strtolower($matches[2]).$matches[3];
    }

    /* HELPERS
     * ---------------------------------------------------------------------------------------------
     */

    private function hueToRgb($v1, $v2, $vh)
    {
        $vh = $vh < 0 ? $vh + 1 : ($vh > 1 ? $vh - 1 : $vh);
        if ($vh * 6 < 1) {
            return $v1 + ($v2 - $v1) * 6 * $vh;
        }
        if ($vh * 2 < 1) {
            return $v2;
        }
        if ($vh * 3 < 2) {
            return $v1 + ($v2 - $v1) * ((2/3) - $vh) * 6;
        }
        return $v1;
    }

    private function roundNumber($n)
    {
        return intval(floor(floatval($n) + 0.5), 10);
    }

    private function clampNumber($n, $min, $max)
    {
        return min(max($n, $min), $max);
    }

    /**
     * PHP port of Javascript's "indexOf" function for strings only
     * Author: Tubal Martin http://blog.margenn.com
     *
     * @param string $haystack
     * @param string $needle
     * @param int    $offset index (optional)
     * @return int
     */
    private function indexOf($haystack, $needle, $offset = 0)
    {
        $index = strpos($haystack, $needle, $offset);

        return ($index !== false) ? $index : -1;
    }

    /**
     * PHP port of Javascript's "slice" function for strings only
     * Author: Tubal Martin http://blog.margenn.com
     * Tests: http://margenn.com/tubal/str_slice/
     *
     * @param string   $str
     * @param int      $start index
     * @param int|bool $end index (optional)
     * @return string
     */
    private function strSlice($str, $start = 0, $end = false)
    {
        if ($end !== false && ($start < 0 || $end <= 0)) {
            $max = strlen($str);

            if ($start < 0) {
                if (($start = $max + $start) < 0) {
                    return '';
                }
            }

            if ($end < 0) {
                if (($end = $max + $end) < 0) {
                    return '';
                }
            }

            if ($end <= $start) {
                return '';
            }
        }

        $slice = ($end === false) ? substr($str, $start) : substr($str, $start, $end - $start);
        return ($slice === false) ? '' : $slice;
    }

    /**
     * Convert strings like "64M" or "30" to int values
     * @param mixed $size
     * @return int
     */
    private function normalizeInt($size)
    {
        if (is_string($size)) {
            switch (substr($size, -1)) {
                case 'M':
                case 'm':
                    return (int) $size * 1048576;
                case 'K':
                case 'k':
                    return (int) $size * 1024;
                case 'G':
                case 'g':
                    return (int) $size * 1073741824;
            }
        }

        return (int) $size;
    }
}
