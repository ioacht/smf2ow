<?php

class ContentTranslator {
    public static function translate($smf_styled_text) {
        $translated = utf8_encode($smf_styled_text);
        $translated = self::translateQuotes($translated);
        $translated = self::translateHSTumbnails($translated);
        $translated = self::parse_bbc($translated);
        $translated = self::translateSmileys($translated);
        return $translated;
    }

    private static function translateSmileys($text) {
        $base_url = OW_SMILEYS_BASE_URL;
        $needles = array( ":)", ";)", ":D", ";D", ">:(", ":(", ":o", "8)","???", "::)", ":P",
            ":-X", ":-\\", ":-*", ":'(");
        $replacement = array(
            "<img src=\"" . $base_url . "smiling.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "winking.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "laughing.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "ROFL.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "FUBAR.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "sad.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "surprised.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "cool.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "wondering.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "it wasn't me.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "tongue.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "speechless.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "thinking.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "makeup.gif\" />&nbsp;",
            "<img src=\"" . $base_url . "crying.gif\" />&nbsp;"
        );
        return str_replace($needles, $replacement, $text);
    }

    private static function translateQuotes($text) {
        $patterns = array("/\\[quote.*author=([^ ]+)[^\\]]*]/", "/\\[\\/quote]/");
        $replacement = array(
            '<blockquote class="ow_quote"><span class="ow_quote_header"><span class="ow_author">Quote from $1<b></b></span></span>
             <span class="ow_quote_cont_wrap"><span class="ow_quote_cont">',
            '</span></span></blockquote>'
        );
        return preg_replace($patterns, $replacement, $text);
    }

    private static function translateHSTumbnails($text) {
        $pattern = "/\\[hs.*width=(\\d+).*height=(\\d+)[^\\]]*]([^\\[]+)\\[\\/hs]/";
        $replacement = '<img src="$3" height="$2" width="$1">';
        return preg_replace($pattern, $replacement, $text);
    }

    // Adopted from the original SMF2.1 source, for a multi-thousand line function galore -_-
    private static function parse_bbc($message)
    {
        $smileys = true;
        $cache_id = '';
        $parse_tags = array();

        global $txt, $scripturl, $context, $modSettings, $user_info;
        static $bbc_codes = array(), $itemcodes = array(), $no_autolink_tags = array();
        static $disabled;

        // Don't waste cycles
        if ($message === '')
            return '';

        // Sift out the bbc for a performance improvement.
        if (empty($bbc_codes) || $message === false || !empty($parse_tags))
        {
            /* The following bbc are formatted as an array, with keys as follows:

                tag: the tag's name - should be lowercase!

                type: one of...
                    - (missing): [tag]parsed content[/tag]
                    - unparsed_equals: [tag=xyz]parsed content[/tag]
                    - parsed_equals: [tag=parsed data]parsed content[/tag]
                    - unparsed_content: [tag]unparsed content[/tag]
                    - closed: [tag], [tag/], [tag /]
                    - unparsed_commas: [tag=1,2,3]parsed content[/tag]
                    - unparsed_commas_content: [tag=1,2,3]unparsed content[/tag]
                    - unparsed_equals_content: [tag=...]unparsed content[/tag]

                parameters: an optional array of parameters, for the form
                  [tag abc=123]content[/tag].  The array is an associative array
                  where the keys are the parameter names, and the values are an
                  array which may contain the following:
                    - match: a regular expression to validate and match the value.
                    - quoted: true if the value should be quoted.
                    - validate: callback to evaluate on the data, which is $data.
                    - value: a string in which to replace $1 with the data.
                      either it or validate may be used, not both.
                    - optional: true if the parameter is optional.

                test: a regular expression to test immediately after the tag's
                  '=', ' ' or ']'.  Typically, should have a \] at the end.
                  Optional.

                content: only available for unparsed_content, closed,
                  unparsed_commas_content, and unparsed_equals_content.
                  $1 is replaced with the content of the tag.  Parameters
                  are replaced in the form {param}.  For unparsed_commas_content,
                  $2, $3, ..., $n are replaced.

                before: only when content is not used, to go before any
                  content.  For unparsed_equals, $1 is replaced with the value.
                  For unparsed_commas, $1, $2, ..., $n are replaced.

                after: similar to before in every way, except that it is used
                  when the tag is closed.

                disabled_content: used in place of content when the tag is
                  disabled.  For closed, default is '', otherwise it is '$1' if
                  block_level is false, '<div>$1</div>' elsewise.

                disabled_before: used in place of before when disabled.  Defaults
                  to '<div>' if block_level, '' if not.

                disabled_after: used in place of after when disabled.  Defaults
                  to '</div>' if block_level, '' if not.

                block_level: set to true the tag is a "block level" tag, similar
                  to HTML.  Block level tags cannot be nested inside tags that are
                  not block level, and will not be implicitly closed as easily.
                  One break following a block level tag may also be removed.

                trim: if set, and 'inside' whitespace after the begin tag will be
                  removed.  If set to 'outside', whitespace after the end tag will
                  meet the same fate.

                validate: except when type is missing or 'closed', a callback to
                  validate the data as $data.  Depending on the tag's type, $data
                  may be a string or an array of strings (corresponding to the
                  replacement.)

                quoted: when type is 'unparsed_equals' or 'parsed_equals' only,
                  may be not set, 'optional', or 'required' corresponding to if
                  the content may be quoted.  This allows the parser to read
                  [tag="abc]def[esdf]"] properly.

                require_parents: an array of tag names, or not set.  If set, the
                  enclosing tag *must* be one of the listed tags, or parsing won't
                  occur.

                require_children: similar to require_parents, if set children
                  won't be parsed if they are not in the list.

                disallow_children: similar to, but very different from,
                  require_children, if it is set the listed tags will not be
                  parsed inside the tag.

                parsed_tags_allowed: an array restricting what BBC can be in the
                  parsed_equals parameter, if desired.
            */

            $codes = array(
                array(
                    'tag' => 'abbr',
                    'type' => 'unparsed_equals',
                    'before' => '<abbr title="$1">',
                    'after' => '</abbr>',
                    'quoted' => 'optional',
                    'disabled_after' => ' ($1)',
                ),
                array(
                    'tag' => 'acronym',
                    'type' => 'unparsed_equals',
                    'before' => '<abbr title="$1">',
                    'after' => '</abbr>',
                    'quoted' => 'optional',
                    'disabled_after' => ' ($1)',
                ),
                array(
                    'tag' => 'anchor',
                    'type' => 'unparsed_equals',
                    'test' => '[#]?([A-Za-z][A-Za-z0-9_\-]*)\]',
                    'before' => '<span id="post_$1">',
                    'after' => '</span>',
                ),
                array(
                    'tag' => 'b',
                    'before' => '<b>',
                    'after' => '</b>',
                ),
                array(
                    'tag' => 'bdo',
                    'type' => 'unparsed_equals',
                    'before' => '<bdo dir="$1">',
                    'after' => '</bdo>',
                    'test' => '(rtl|ltr)\]',
                    'block_level' => true,
                ),
                array(
                    'tag' => 'black',
                    'before' => '<span style="color: black;">',
                    'after' => '</span>',
                ),
                array(
                    'tag' => 'blue',
                    'before' => '<span style="color: blue;">',
                    'after' => '</span>',
                ),
                array(
                    'tag' => 'br',
                    'type' => 'closed',
                    'content' => '<br>',
                ),
                array(
                    'tag' => 'center',
                    'before' => '<div class="centertext">',
                    'after' => '</div>',
                    'block_level' => true,
                ),
                array(
                    'tag' => 'code',
                    'type' => 'unparsed_content',
                    'content' => '<div class="codeheader"><span class="code floatleft">' . $txt['code'] . '</span> <a href="javascript:void(0);" onclick="return smfSelectText(this);" class="codeoperation">' . $txt['code_select'] . '</a></div><code class="bbc_code">$1</code>',
                    // @todo Maybe this can be simplified?
                    'validate' => isset($disabled['code']) ? null : function (&$tag, &$data, $disabled) use ($context)
                        {
                            if (!isset($disabled['code']))
                            {
                                $php_parts = preg_split('~(&lt;\?php|\?&gt;)~', $data, -1, PREG_SPLIT_DELIM_CAPTURE);

                                for ($php_i = 0, $php_n = count($php_parts); $php_i < $php_n; $php_i++)
                                {
                                    // Do PHP code coloring?
                                    if ($php_parts[$php_i] != '&lt;?php')
                                        continue;

                                    $php_string = '';
                                    while ($php_i + 1 < count($php_parts) && $php_parts[$php_i] != '?&gt;')
                                    {
                                        $php_string .= $php_parts[$php_i];
                                        $php_parts[$php_i++] = '';
                                    }
                                    $php_parts[$php_i] = highlight_php_code($php_string . $php_parts[$php_i]);
                                }

                                // Fix the PHP code stuff...
                                $data = str_replace("<pre style=\"display: inline;\">\t</pre>", "\t", implode('', $php_parts));
                                $data = str_replace("\t", "<span style=\"white-space: pre;\">\t</span>", $data);

                                // Recent Opera bug requiring temporary fix. &nsbp; is needed before </code> to avoid broken selection.
                                if ($context['browser']['is_opera'])
                                    $data .= '&nbsp;';
                            }
                        },
                    'block_level' => true,
                ),
                array(
                    'tag' => 'code',
                    'type' => 'unparsed_equals_content',
                    'content' => '<div class="codeheader"><span class="code floatleft">' . $txt['code'] . '</span> ($2) <a href="#" onclick="return smfSelectText(this);" class="codeoperation">' . $txt['code_select'] . '</a></div><code class="bbc_code">$1</code>',
                    // @todo Maybe this can be simplified?
                    'validate' => isset($disabled['code']) ? null : function (&$tag, &$data, $disabled) use ($context)
                        {
                            if (!isset($disabled['code']))
                            {
                                $php_parts = preg_split('~(&lt;\?php|\?&gt;)~', $data[0], -1, PREG_SPLIT_DELIM_CAPTURE);

                                for ($php_i = 0, $php_n = count($php_parts); $php_i < $php_n; $php_i++)
                                {
                                    // Do PHP code coloring?
                                    if ($php_parts[$php_i] != '&lt;?php')
                                        continue;

                                    $php_string = '';
                                    while ($php_i + 1 < count($php_parts) && $php_parts[$php_i] != '?&gt;')
                                    {
                                        $php_string .= $php_parts[$php_i];
                                        $php_parts[$php_i++] = '';
                                    }
                                    $php_parts[$php_i] = highlight_php_code($php_string . $php_parts[$php_i]);
                                }

                                // Fix the PHP code stuff...
                                $data[0] = str_replace("<pre style=\"display: inline;\">\t</pre>", "\t", implode('', $php_parts));
                                $data[0] = str_replace("\t", "<span style=\"white-space: pre;\">\t</span>", $data[0]);

                                // Recent Opera bug requiring temporary fix. &nsbp; is needed before </code> to avoid broken selection.
                                if ($context['browser']['is_opera'])
                                    $data[0] .= '&nbsp;';
                            }
                        },
                    'block_level' => true,
                ),
                array(
                    'tag' => 'color',
                    'type' => 'unparsed_equals',
                    'test' => '(#[\da-fA-F]{3}|#[\da-fA-F]{6}|[A-Za-z]{1,20}|rgb\((?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\s?,\s?){2}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\))\]',
                    'before' => '<span style="color: $1;" >',
                    'after' => '</span>',
                ),
                array(
                    'tag' => 'email',
                    'type' => 'unparsed_equals',
                    'before' => '<a href="mailto:$1" class="bbc_email">',
                    'after' => '</a>',
                    // @todo Should this respect guest_hideContacts?
                    'disallow_children' => array('email', 'ftp', 'url', 'iurl'),
                    'disabled_after' => ' ($1)',
                ),
                array(
                    'tag' => 'font',
                    'type' => 'unparsed_equals',
                    'test' => '[A-Za-z0-9_,\-\s]+?\]',
                    'before' => '<span style="font-family: $1;">',
                    'after' => '</span>',
                ),
                array(
                    'tag' => 'ftp',
                    'type' => 'unparsed_content',
                    'content' => '<a href="$1" class="bbc_ftp new_win" target="_blank">$1</a>',
                    'validate' => function (&$tag, &$data, $disabled)
                        {
                            $data = strtr($data, array('<br>' => ''));
                            if (strpos($data, 'ftp://') !== 0 && strpos($data, 'ftps://') !== 0)
                                $data = 'ftp://' . $data;
                        },
                ),
                array(
                    'tag' => 'ftp',
                    'type' => 'unparsed_equals',
                    'before' => '<a href="$1" class="bbc_ftp new_win" target="_blank">',
                    'after' => '</a>',
                    'validate' => function (&$tag, &$data, $disabled)
                        {
                            if (strpos($data, 'ftp://') !== 0 && strpos($data, 'ftps://') !== 0)
                                $data = 'ftp://' . $data;
                        },
                    'disallow_children' => array('email', 'ftp', 'url', 'iurl'),
                    'disabled_after' => ' ($1)',
                ),
                array(
                    'tag' => 'green',
                    'before' => '<span style="color: green;" >',
                    'after' => '</span>',
                ),
                array(
                    'tag' => 'html',
                    'type' => 'unparsed_content',
                    'content' => '$1',
                    'block_level' => true,
                    'disabled_content' => '$1',
                ),
                array(
                    'tag' => 'hr',
                    'type' => 'closed',
                    'content' => '<hr>',
                    'block_level' => true,
                ),
                array(
                    'tag' => 'i',
                    'before' => '<i>',
                    'after' => '</i>',
                ),
                array(
                    'tag' => 'img',
                    'type' => 'unparsed_content',
                    'parameters' => array(
                        'alt' => array('optional' => true),
                        'title' => array('optional' => true),
                        'width' => array('optional' => true, 'value' => ' width="$1"', 'match' => '(\d+)'),
                        'height' => array('optional' => true, 'value' => ' height="$1"', 'match' => '(\d+)'),
                    ),
                    'content' => '<img src="$1" alt="{alt}" title="{title}"{width}{height} class="bbc_img resized">',
                    'validate' => function (&$tag, &$data, $disabled)
                        {
                            global $image_proxy_enabled, $image_proxy_secret, $boardurl;

                            $data = strtr($data, array('<br>' => ''));
                            if (strpos($data, 'http://') !== 0 && strpos($data, 'https://') !== 0)
                                $data = 'http://' . $data;

                            if (substr($data, 0, 8) != 'https://' && $image_proxy_enabled)
                                $data = $boardurl . '/proxy.php?request=' . urlencode($data) . '&hash=' . md5($data . $image_proxy_secret);
                        },
                    'disabled_content' => '($1)',
                ),
                array(
                    'tag' => 'img',
                    'type' => 'unparsed_content',
                    'content' => '<img src="$1" alt="" class="bbc_img">',
                    'validate' => function (&$tag, &$data, $disabled)
                        {
                            global $image_proxy_enabled, $image_proxy_secret, $boardurl;

                            $data = strtr($data, array('<br>' => ''));
                            if (strpos($data, 'http://') !== 0 && strpos($data, 'https://') !== 0)
                                $data = 'http://' . $data;

                            if (substr($data, 0, 8) != 'https://' && $image_proxy_enabled)
                                $data = $boardurl . '/proxy.php?request=' . urlencode($data) . '&hash=' . md5($data . $image_proxy_secret);
                        },
                    'disabled_content' => '($1)',
                ),
                array(
                    'tag' => 'iurl',
                    'type' => 'unparsed_content',
                    'content' => '<a href="$1">$1</a>',
                ),
                array(
                    'tag' => 'iurl',
                    'type' => 'unparsed_equals',
                    'before' => '<a href="$1">',
                    'after' => '</a>',
                ),
                array(
                    'tag' => 'left',
                    'before' => '<div style="text-align: left;">',
                    'after' => '</div>',
                    'block_level' => true,
                ),
                array(
                    'tag' => 'li',
                    'before' => '<li>',
                    'after' => '</li>',
                    'trim' => 'outside',
                    'require_parents' => array('list'),
                    'block_level' => true,
                    'disabled_before' => '',
                    'disabled_after' => '<br>',
                ),
                array(
                    'tag' => 'list',
                    'before' => '<ul class="bbc_list">',
                    'after' => '</ul>',
                    'trim' => 'inside',
                    'require_children' => array('li', 'list'),
                    'block_level' => true,
                ),
                array(
                    'tag' => 'list',
                    'parameters' => array(
                        'type' => array('match' => '(none|disc|circle|square|decimal|decimal-leading-zero|lower-roman|upper-roman|lower-alpha|upper-alpha|lower-greek|lower-latin|upper-latin|hebrew|armenian|georgian|cjk-ideographic|hiragana|katakana|hiragana-iroha|katakana-iroha)'),
                    ),
                    'before' => '<ul class="bbc_list" style="list-style-type: {type};">',
                    'after' => '</ul>',
                    'trim' => 'inside',
                    'require_children' => array('li'),
                    'block_level' => true,
                ),
                array(
                    'tag' => 'ltr',
                    'before' => '<div dir="ltr">',
                    'after' => '</div>',
                    'block_level' => true,
                ),
                array(
                    'tag' => 'me',
                    'type' => 'unparsed_equals',
                    'before' => '<div class="meaction">* $1 ',
                    'after' => '</div>',
                    'quoted' => 'optional',
                    'block_level' => true,
                    'disabled_before' => '/me ',
                    'disabled_after' => '<br>',
                ),
                array(
                    'tag' => 'member',
                    'type' => 'unparsed_equals',
                    'before' => '<a href="' . $scripturl . '?action=profile;u=$1" class="mention">@',
                    'after' => '</a>',
                ),
                array(
                    'tag' => 'move',
                    'before' => '<marquee>',
                    'after' => '</marquee>',
                    'block_level' => true,
                    'disallow_children' => array('move'),
                ),
                array(
                    'tag' => 'nobbc',
                    'type' => 'unparsed_content',
                    'content' => '$1',
                ),
                array(
                    'tag' => 'php',
                    'type' => 'unparsed_content',
                    'content' => '<span class="phpcode">$1</span>',
                    'validate' => isset($disabled['php']) ? null : function (&$tag, &$data, $disabled)
                        {
                            if (!isset($disabled['php']))
                            {
                                $add_begin = substr(trim($data), 0, 5) != '&lt;?';
                                $data = highlight_php_code($add_begin ? '&lt;?php ' . $data . '?&gt;' : $data);
                                if ($add_begin)
                                    $data = preg_replace(array('~^(.+?)&lt;\?.{0,40}?php(?:&nbsp;|\s)~', '~\?&gt;((?:</(font|span)>)*)$~'), '$1', $data, 2);
                            }
                        },
                    'block_level' => false,
                    'disabled_content' => '$1',
                ),
                array(
                    'tag' => 'pre',
                    'before' => '<pre>',
                    'after' => '</pre>',
                ),
                array(
                    'tag' => 'quote',
                    'before' => '<div class="quoteheader">' . $txt['quote'] . '</div><blockquote>',
                    'after' => '</blockquote><div class="quotefooter"></div>',
                    'trim' => 'both',
                    'block_level' => true,
                ),
                array(
                    'tag' => 'quote',
                    'parameters' => array(
                        'author' => array('match' => '(.{1,192}?)', 'quoted' => true),
                    ),
                    'before' => '<div class="quoteheader">' . $txt['quote_from'] . ': {author}</div><blockquote>',
                    'after' => '</blockquote><div class="quotefooter"></div>',
                    'trim' => 'both',
                    'block_level' => true,
                ),
                array(
                    'tag' => 'quote',
                    'type' => 'parsed_equals',
                    'before' => '<div class="quoteheader">' . $txt['quote_from'] . ': $1</div><blockquote>',
                    'after' => '</blockquote><div class="quotefooter"></div>',
                    'trim' => 'both',
                    'quoted' => 'optional',
                    // Don't allow everything to be embedded with the author name.
                    'parsed_tags_allowed' => array('url', 'iurl', 'ftp'),
                    'block_level' => true,
                ),
                array(
                    'tag' => 'quote',
                    'parameters' => array(
                        'author' => array('match' => '([^<>]{1,192}?)'),
                        'link' => array('match' => '(?:board=\d+;)?((?:topic|threadid)=[\dmsg#\./]{1,40}(?:;start=[\dmsg#\./]{1,40})?|msg=\d+?|action=profile;u=\d+)'),
                        'date' => array('match' => '(\d+)', 'validate' => 'timeformat'),
                    ),
                    'before' => '<div class="quoteheader"><a href="' . $scripturl . '?{link}">' . $txt['quote_from'] . ': {author} ' . $txt['search_on'] . ' {date}</a></div><blockquote>',
                    'after' => '</blockquote><div class="quotefooter"></div>',
                    'trim' => 'both',
                    'block_level' => true,
                ),
                array(
                    'tag' => 'quote',
                    'parameters' => array(
                        'author' => array('match' => '(.{1,192}?)'),
                    ),
                    'before' => '<div class="quoteheader">' . $txt['quote_from'] . ': {author}</div><blockquote>',
                    'after' => '</blockquote><div class="quotefooter"></div>',
                    'trim' => 'both',
                    'block_level' => true,
                ),
                array(
                    'tag' => 'red',
                    'before' => '<span style="color: red;" >',
                    'after' => '</span>',
                ),
                array(
                    'tag' => 'right',
                    'before' => '<div style="text-align: right;">',
                    'after' => '</div>',
                    'block_level' => true,
                ),
                array(
                    'tag' => 'rtl',
                    'before' => '<div dir="rtl">',
                    'after' => '</div>',
                    'block_level' => true,
                ),
                array(
                    'tag' => 's',
                    'before' => '<del>',
                    'after' => '</del>',
                ),
                array(
                    'tag' => 'size',
                    'type' => 'unparsed_equals',
                    'test' => '([1-9][\d]?p[xt]|small(?:er)?|large[r]?|x[x]?-(?:small|large)|medium|(0\.[1-9]|[1-9](\.[\d][\d]?)?)?em)\]',
                    'before' => '<span style="font-size: $1;" >',
                    'after' => '</span>',
                ),
                array(
                    'tag' => 'size',
                    'type' => 'unparsed_equals',
                    'test' => '[1-7]\]',
                    'before' => '<span style="font-size: $1;" >',
                    'after' => '</span>',
                    'validate' => function (&$tag, &$data, $disabled)
                        {
                            $sizes = array(1 => 0.7, 2 => 1.0, 3 => 1.35, 4 => 1.45, 5 => 2.0, 6 => 2.65, 7 => 3.95);
                            $data = $sizes[$data] . 'em';
                        },
                ),
                array(
                    'tag' => 'sub',
                    'before' => '<sub>',
                    'after' => '</sub>',
                ),
                array(
                    'tag' => 'sup',
                    'before' => '<sup>',
                    'after' => '</sup>',
                ),
                array(
                    'tag' => 'table',
                    'before' => '<table>',
                    'after' => '</table>',
                    'trim' => 'inside',
                    'require_children' => array('tr'),
                    'block_level' => true,
                ),
                array(
                    'tag' => 'td',
                    'before' => '<td>',
                    'after' => '</td>',
                    'require_parents' => array('tr'),
                    'trim' => 'outside',
                    'block_level' => true,
                    'disabled_before' => '',
                    'disabled_after' => '',
                ),
                array(
                    'tag' => 'time',
                    'type' => 'unparsed_content',
                    'content' => '$1',
                    'validate' => function (&$tag, &$data, $disabled)
                        {
                            if (is_numeric($data))
                                $data = timeformat($data);
                            else
                                $tag['content'] = '[time]$1[/time]';
                        },
                ),
                array(
                    'tag' => 'tr',
                    'before' => '<tr>',
                    'after' => '</tr>',
                    'require_parents' => array('table'),
                    'require_children' => array('td'),
                    'trim' => 'both',
                    'block_level' => true,
                    'disabled_before' => '',
                    'disabled_after' => '',
                ),
                array(
                    'tag' => 'tt',
                    'before' => '<span class="bbc_tt">',
                    'after' => '</span>',
                ),
                array(
                    'tag' => 'u',
                    'before' => '<u>',
                    'after' => '</u>',
                ),
                array(
                    'tag' => 'url',
                    'type' => 'unparsed_content',
                    'content' => '<a href="$1" class="bbc_link" target="_blank">$1</a>',
                    'validate' => function (&$tag, &$data, $disabled)
                        {
                            $data = strtr($data, array('<br>' => ''));
                            if (strpos($data, 'http://') !== 0 && strpos($data, 'https://') !== 0)
                                $data = 'http://' . $data;
                        },
                ),
                array(
                    'tag' => 'url',
                    'type' => 'unparsed_equals',
                    'before' => '<a href="$1" class="bbc_link" target="_blank">',
                    'after' => '</a>',
                    'validate' => function (&$tag, &$data, $disabled)
                        {
                            if (strpos($data, 'http://') !== 0 && strpos($data, 'https://') !== 0)
                                $data = 'http://' . $data;
                        },
                    'disallow_children' => array('email', 'ftp', 'url', 'iurl'),
                    'disabled_after' => ' ($1)',
                ),
                array(
                    'tag' => 'white',
                    'before' => '<span style="color: white;">',
                    'after' => '</span>',
                ),
            );

            // Inside these tags autolink is not recommendable.
            $no_autolink_tags = array(
                'url',
                'iurl',
                'ftp',
                'email',
            );


            // So the parser won't skip them.
            $itemcodes = array(
                '*' => 'disc',
                '@' => 'disc',
                '+' => 'square',
                'x' => 'square',
                '#' => 'square',
                'o' => 'circle',
                'O' => 'circle',
                '0' => 'circle',
            );
            if (!isset($disabled['li']) && !isset($disabled['list']))
            {
                foreach ($itemcodes as $c => $dummy)
                    $bbc_codes[$c] = array();
            }

            // Shhhh!
            if (!isset($disabled['color']))
            {
                $codes[] = array(
                    'tag' => 'chrissy',
                    'before' => '<span style="color: #cc0099;">',
                    'after' => ' :-*</span>',
                );
                $codes[] = array(
                    'tag' => 'kissy',
                    'before' => '<span style="color: #cc0099;">',
                    'after' => ' :-*</span>',
                );
            }

            foreach ($codes as $code)
            {
                // If we are not doing every tag only do ones we are interested in.
                if (empty($parse_tags) || in_array($code['tag'], $parse_tags))
                    $bbc_codes[substr($code['tag'], 0, 1)][] = $code;
            }
            $codes = null;
        }

        if ($smileys === 'print')
        {
            // [glow], [shadow], and [move] can't really be printed.
            $disabled['glow'] = true;
            $disabled['shadow'] = true;
            $disabled['move'] = true;

            // Colors can't well be displayed... supposed to be black and white.
            $disabled['color'] = true;
            $disabled['black'] = true;
            $disabled['blue'] = true;
            $disabled['white'] = true;
            $disabled['red'] = true;
            $disabled['green'] = true;
            $disabled['me'] = true;

            // Color coding doesn't make sense.
            $disabled['php'] = true;

            // Links are useless on paper... just show the link.
            $disabled['ftp'] = true;
            $disabled['url'] = true;
            $disabled['iurl'] = true;
            $disabled['email'] = true;
            $disabled['flash'] = true;

            // @todo Change maybe?
            if (!isset($_GET['images']))
                $disabled['img'] = true;

            // @todo Interface/setting to add more?
        }

        $open_tags = array();
        $message = strtr($message, array("\n" => '<br>'));

        // The non-breaking-space looks a bit different each time.
        $non_breaking_space = $context['utf8'] ? '\x{A0}' : '\xA0';

        $pos = -1;
        while ($pos !== false)
        {
            $last_pos = isset($last_pos) ? max($pos, $last_pos) : $pos;
            $pos = strpos($message, '[', $pos + 1);

            // Failsafe.
            if ($pos === false || $last_pos > $pos)
                $pos = strlen($message) + 1;

            // Can't have a one letter smiley, URL, or email! (sorry.)
            if ($last_pos < $pos - 1)
            {
                // Make sure the $last_pos is not negative.
                $last_pos = max($last_pos, 0);

                // Pick a block of data to do some raw fixing on.
                $data = substr($message, $last_pos, $pos - $last_pos);

                // Take care of some HTML!
                if (!empty($modSettings['enablePostHTML']) && strpos($data, '&lt;') !== false)
                {
                    $data = preg_replace('~&lt;a\s+href=((?:&quot;)?)((?:https?://|ftps?://|mailto:)\S+?)\\1&gt;~i', '[url=$2]', $data);
                    $data = preg_replace('~&lt;/a&gt;~i', '[/url]', $data);

                    // <br> should be empty.
                    $empty_tags = array('br', 'hr');
                    foreach ($empty_tags as $tag)
                        $data = str_replace(array('&lt;' . $tag . '&gt;', '&lt;' . $tag . '/&gt;', '&lt;' . $tag . ' /&gt;'), '[' . $tag . ' /]', $data);

                    // b, u, i, s, pre... basic tags.
                    $closable_tags = array('b', 'u', 'i', 's', 'em', 'ins', 'del', 'pre', 'blockquote');
                    foreach ($closable_tags as $tag)
                    {
                        $diff = substr_count($data, '&lt;' . $tag . '&gt;') - substr_count($data, '&lt;/' . $tag . '&gt;');
                        $data = strtr($data, array('&lt;' . $tag . '&gt;' => '<' . $tag . '>', '&lt;/' . $tag . '&gt;' => '</' . $tag . '>'));

                        if ($diff > 0)
                            $data = substr($data, 0, -1) . str_repeat('</' . $tag . '>', $diff) . substr($data, -1);
                    }

                    // Do <img ...> - with security... action= -> action-.
                    preg_match_all('~&lt;img\s+src=((?:&quot;)?)((?:https?://|ftps?://)\S+?)\\1(?:\s+alt=(&quot;.*?&quot;|\S*?))?(?:\s?/)?&gt;~i', $data, $matches, PREG_PATTERN_ORDER);
                    if (!empty($matches[0]))
                    {
                        $replaces = array();
                        foreach ($matches[2] as $match => $imgtag)
                        {
                            $alt = empty($matches[3][$match]) ? '' : ' alt=' . preg_replace('~^&quot;|&quot;$~', '', $matches[3][$match]);

                            // Remove action= from the URL - no funny business, now.
                            if (preg_match('~action(=|%3d)(?!dlattach)~i', $imgtag) != 0)
                                $imgtag = preg_replace('~action(?:=|%3d)(?!dlattach)~i', 'action-', $imgtag);

                            // Check if the image is larger than allowed.
                            if (!empty($modSettings['max_image_width']) && !empty($modSettings['max_image_height']))
                            {
                                list ($width, $height) = url_image_size($imgtag);

                                if (!empty($modSettings['max_image_width']) && $width > $modSettings['max_image_width'])
                                {
                                    $height = (int) (($modSettings['max_image_width'] * $height) / $width);
                                    $width = $modSettings['max_image_width'];
                                }

                                if (!empty($modSettings['max_image_height']) && $height > $modSettings['max_image_height'])
                                {
                                    $width = (int) (($modSettings['max_image_height'] * $width) / $height);
                                    $height = $modSettings['max_image_height'];
                                }

                                // Set the new image tag.
                                $replaces[$matches[0][$match]] = '[img width=' . $width . ' height=' . $height . $alt . ']' . $imgtag . '[/img]';
                            }
                            else
                                $replaces[$matches[0][$match]] = '[img' . $alt . ']' . $imgtag . '[/img]';
                        }

                        $data = strtr($data, $replaces);
                    }
                }

                if (!empty($modSettings['autoLinkUrls']))
                {
                    // Are we inside tags that should be auto linked?
                    $no_autolink_area = false;
                    if (!empty($open_tags))
                    {
                        foreach ($open_tags as $open_tag)
                            if (in_array($open_tag['tag'], $no_autolink_tags))
                                $no_autolink_area = true;
                    }

                    // Don't go backwards.
                    // @todo Don't think is the real solution....
                    $lastAutoPos = isset($lastAutoPos) ? $lastAutoPos : 0;
                    if ($pos < $lastAutoPos)
                        $no_autolink_area = true;
                    $lastAutoPos = $pos;

                    if (!$no_autolink_area)
                    {
                        // Parse any URLs.... have to get rid of the @ problems some things cause... stupid email addresses.
                        if (!isset($disabled['url']) && (strpos($data, '://') !== false || strpos($data, 'www.') !== false) && strpos($data, '[url') === false)
                        {
                            // Switch out quotes really quick because they can cause problems.
                            $data = strtr($data, array('&#039;' => '\'', '&nbsp;' => $context['utf8'] ? "\xC2\xA0" : "\xA0", '&quot;' => '>">', '"' => '<"<', '&lt;' => '<lt<'));

                            // Only do this if the preg survives.
                            if (is_string($result = preg_replace(array(
                                '~(?<=[\s>\.(;\'"]|^)((?:http|https)://[\w\-_%@:|]+(?:\.[\w\-_%]+)*(?::\d+)?(?:/[\w\-_\~%\.@!,\?&;=#(){}+:\'\\\\]*)*[/\w\-_\~%@\?;=#}\\\\]?)~i',
                                '~(?<=[\s>\.(;\'"]|^)((?:ftp|ftps)://[\w\-_%@:|]+(?:\.[\w\-_%]+)*(?::\d+)?(?:/[\w\-_\~%\.@,\?&;=#(){}+:\'\\\\]*)*[/\w\-_\~%@\?;=#}\\\\]?)~i',
                                '~(?<=[\s>(\'<]|^)(www(?:\.[\w\-_]+)+(?::\d+)?(?:/[\w\-_\~%\.@!,\?&;=#(){}+:\'\\\\]*)*[/\w\-_\~%@\?;=#}\\\\])~i'
                            ), array(
                                '[url]$1[/url]',
                                '[ftp]$1[/ftp]',
                                '[url=http://$1]$1[/url]'
                            ), $data)))
                                $data = $result;

                            $data = strtr($data, array('\'' => '&#039;', $context['utf8'] ? "\xC2\xA0" : "\xA0" => '&nbsp;', '>">' => '&quot;', '<"<' => '"', '<lt<' => '&lt;'));
                        }

                        // Next, emails...
                        if (!isset($disabled['email']) && strpos($data, '@') !== false && strpos($data, '[email') === false)
                        {
                            $data = preg_replace('~(?<=[\?\s' . $non_breaking_space . '\[\]()*\\\;>]|^)([\w\-\.]{1,80}@[\w\-]+\.[\w\-\.]+[\w\-])(?=[?,\s' . $non_breaking_space . '\[\]()*\\\]|$|<br>|&nbsp;|&gt;|&lt;|&quot;|&#039;|\.(?:\.|;|&nbsp;|\s|$|<br>))~' . ($context['utf8'] ? 'u' : ''), '[email]$1[/email]', $data);
                            $data = preg_replace('~(?<=<br>)([\w\-\.]{1,80}@[\w\-]+\.[\w\-\.]+[\w\-])(?=[?\.,;\s' . $non_breaking_space . '\[\]()*\\\]|$|<br>|&nbsp;|&gt;|&lt;|&quot;|&#039;)~' . ($context['utf8'] ? 'u' : ''), '[email]$1[/email]', $data);
                        }
                    }
                }

                $data = strtr($data, array("\t" => '&nbsp;&nbsp;&nbsp;'));

                // If it wasn't changed, no copying or other boring stuff has to happen!
                if ($data != substr($message, $last_pos, $pos - $last_pos))
                {
                    $message = substr($message, 0, $last_pos) . $data . substr($message, $pos);

                    // Since we changed it, look again in case we added or removed a tag.  But we don't want to skip any.
                    $old_pos = strlen($data) + $last_pos;
                    $pos = strpos($message, '[', $last_pos);
                    $pos = $pos === false ? $old_pos : min($pos, $old_pos);
                }
            }

            // Are we there yet?  Are we there yet?
            if ($pos >= strlen($message) - 1)
                break;

            $tags = strtolower($message[$pos + 1]);

            if ($tags == '/' && !empty($open_tags))
            {
                $pos2 = strpos($message, ']', $pos + 1);
                if ($pos2 == $pos + 2)
                    continue;

                $look_for = strtolower(substr($message, $pos + 2, $pos2 - $pos - 2));

                $to_close = array();
                $block_level = null;

                do
                {
                    $tag = array_pop($open_tags);
                    if (!$tag)
                        break;

                    if (!empty($tag['block_level']))
                    {
                        // Only find out if we need to.
                        if ($block_level === false)
                        {
                            array_push($open_tags, $tag);
                            break;
                        }

                        // The idea is, if we are LOOKING for a block level tag, we can close them on the way.
                        if (strlen($look_for) > 0 && isset($bbc_codes[$look_for[0]]))
                        {
                            foreach ($bbc_codes[$look_for[0]] as $temp)
                                if ($temp['tag'] == $look_for)
                                {
                                    $block_level = !empty($temp['block_level']);
                                    break;
                                }
                        }

                        if ($block_level !== true)
                        {
                            $block_level = false;
                            array_push($open_tags, $tag);
                            break;
                        }
                    }

                    $to_close[] = $tag;
                }
                while ($tag['tag'] != $look_for);

                // Did we just eat through everything and not find it?
                if ((empty($open_tags) && (empty($tag) || $tag['tag'] != $look_for)))
                {
                    $open_tags = $to_close;
                    continue;
                }
                elseif (!empty($to_close) && $tag['tag'] != $look_for)
                {
                    if ($block_level === null && isset($look_for[0], $bbc_codes[$look_for[0]]))
                    {
                        foreach ($bbc_codes[$look_for[0]] as $temp)
                            if ($temp['tag'] == $look_for)
                            {
                                $block_level = !empty($temp['block_level']);
                                break;
                            }
                    }

                    // We're not looking for a block level tag (or maybe even a tag that exists...)
                    if (!$block_level)
                    {
                        foreach ($to_close as $tag)
                            array_push($open_tags, $tag);
                        continue;
                    }
                }

                foreach ($to_close as $tag)
                {
                    $message = substr($message, 0, $pos) . "\n" . $tag['after'] . "\n" . substr($message, $pos2 + 1);
                    $pos += strlen($tag['after']) + 2;
                    $pos2 = $pos - 1;

                    // See the comment at the end of the big loop - just eating whitespace ;).
                    if (!empty($tag['block_level']) && substr($message, $pos, 6) == '<br>')
                        $message = substr($message, 0, $pos) . substr($message, $pos + 6);
                    if (!empty($tag['trim']) && $tag['trim'] != 'inside' && preg_match('~(<br>|&nbsp;|\s)*~', substr($message, $pos), $matches) != 0)
                        $message = substr($message, 0, $pos) . substr($message, $pos + strlen($matches[0]));
                }

                if (!empty($to_close))
                {
                    $to_close = array();
                    $pos--;
                }

                continue;
            }

            // No tags for this character, so just keep going (fastest possible course.)
            if (!isset($bbc_codes[$tags]))
                continue;

            $inside = empty($open_tags) ? null : $open_tags[count($open_tags) - 1];
            $tag = null;
            foreach ($bbc_codes[$tags] as $possible)
            {
                $pt_strlen = strlen($possible['tag']);

                // Not a match?
                if (strtolower(substr($message, $pos + 1, $pt_strlen)) != $possible['tag'])
                    continue;

                $next_c = $message[$pos + 1 + $pt_strlen];

                // A test validation?
                if (isset($possible['test']) && preg_match('~^' . $possible['test'] . '~', substr($message, $pos + 1 + $pt_strlen + 1)) === 0)
                    continue;
                // Do we want parameters?
                elseif (!empty($possible['parameters']))
                {
                    if ($next_c != ' ')
                        continue;
                }
                elseif (isset($possible['type']))
                {
                    // Do we need an equal sign?
                    if (in_array($possible['type'], array('unparsed_equals', 'unparsed_commas', 'unparsed_commas_content', 'unparsed_equals_content', 'parsed_equals')) && $next_c != '=')
                        continue;
                    // Maybe we just want a /...
                    if ($possible['type'] == 'closed' && $next_c != ']' && substr($message, $pos + 1 + $pt_strlen, 2) != '/]' && substr($message, $pos + 1 + $pt_strlen, 3) != ' /]')
                        continue;
                    // An immediate ]?
                    if ($possible['type'] == 'unparsed_content' && $next_c != ']')
                        continue;
                }
                // No type means 'parsed_content', which demands an immediate ] without parameters!
                elseif ($next_c != ']')
                    continue;

                // Check allowed tree?
                if (isset($possible['require_parents']) && ($inside === null || !in_array($inside['tag'], $possible['require_parents'])))
                    continue;
                elseif (isset($inside['require_children']) && !in_array($possible['tag'], $inside['require_children']))
                    continue;
                // If this is in the list of disallowed child tags, don't parse it.
                elseif (isset($inside['disallow_children']) && in_array($possible['tag'], $inside['disallow_children']))
                    continue;

                $pos1 = $pos + 1 + $pt_strlen + 1;

                // Quotes can have alternate styling, we do this php-side due to all the permutations of quotes.
                if ($possible['tag'] == 'quote')
                {
                    // Start with standard
                    $quote_alt = false;
                    foreach ($open_tags as $open_quote)
                    {
                        // Every parent quote this quote has flips the styling
                        if ($open_quote['tag'] == 'quote')
                            $quote_alt = !$quote_alt;
                    }
                    // Add a class to the quote to style alternating blockquotes
                    $possible['before'] = strtr($possible['before'], array('<blockquote>' => '<blockquote class="bbc_' . ($quote_alt ? 'alternate' : 'standard') . '_quote">'));
                }

                // This is long, but it makes things much easier and cleaner.
                if (!empty($possible['parameters']))
                {
                    $preg = array();
                    foreach ($possible['parameters'] as $p => $info)
                        $preg[] = '(\s+' . $p . '=' . (empty($info['quoted']) ? '' : '&quot;') . (isset($info['match']) ? $info['match'] : '(.+?)') . (empty($info['quoted']) ? '' : '&quot;') . ')' . (empty($info['optional']) ? '' : '?');

                    // Okay, this may look ugly and it is, but it's not going to happen much and it is the best way of allowing any order of parameters but still parsing them right.
                    $match = false;
                    $orders = ContentTranslator::permute($preg);
                    foreach ($orders as $p)
                        if (preg_match('~^' . implode('', $p) . '\]~i', substr($message, $pos1 - 1), $matches) != 0)
                        {
                            $match = true;
                            break;
                        }

                    // Didn't match our parameter list, try the next possible.
                    if (!$match)
                        continue;

                    $params = array();
                    for ($i = 1, $n = count($matches); $i < $n; $i += 2)
                    {
                        $key = strtok(ltrim($matches[$i]), '=');
                        if (isset($possible['parameters'][$key]['value']))
                            $params['{' . $key . '}'] = strtr($possible['parameters'][$key]['value'], array('$1' => $matches[$i + 1]));
                        elseif (isset($possible['parameters'][$key]['validate']))
                            $params['{' . $key . '}'] = $possible['parameters'][$key]['validate']($matches[$i + 1]);
                        else
                            $params['{' . $key . '}'] = $matches[$i + 1];

                        // Just to make sure: replace any $ or { so they can't interpolate wrongly.
                        $params['{' . $key . '}'] = strtr($params['{' . $key . '}'], array('$' => '&#036;', '{' => '&#123;'));
                    }

                    foreach ($possible['parameters'] as $p => $info)
                    {
                        if (!isset($params['{' . $p . '}']))
                            $params['{' . $p . '}'] = '';
                    }

                    $tag = $possible;

                    // Put the parameters into the string.
                    if (isset($tag['before']))
                        $tag['before'] = strtr($tag['before'], $params);
                    if (isset($tag['after']))
                        $tag['after'] = strtr($tag['after'], $params);
                    if (isset($tag['content']))
                        $tag['content'] = strtr($tag['content'], $params);

                    $pos1 += strlen($matches[0]) - 1;
                }
                else
                    $tag = $possible;
                break;
            }

            // Item codes are complicated buggers... they are implicit [li]s and can make [list]s!
            if ($smileys !== false && $tag === null && isset($itemcodes[$message[$pos + 1]]) && $message[$pos + 2] == ']' && !isset($disabled['list']) && !isset($disabled['li']))
            {
                if ($message[$pos + 1] == '0' && !in_array($message[$pos - 1], array(';', ' ', "\t", "\n", '>')))
                    continue;

                $tag = $itemcodes[$message[$pos + 1]];

                // First let's set up the tree: it needs to be in a list, or after an li.
                if ($inside === null || ($inside['tag'] != 'list' && $inside['tag'] != 'li'))
                {
                    $open_tags[] = array(
                        'tag' => 'list',
                        'after' => '</ul>',
                        'block_level' => true,
                        'require_children' => array('li'),
                        'disallow_children' => isset($inside['disallow_children']) ? $inside['disallow_children'] : null,
                    );
                    $code = '<ul class="bbc_list">';
                }
                // We're in a list item already: another itemcode?  Close it first.
                elseif ($inside['tag'] == 'li')
                {
                    array_pop($open_tags);
                    $code = '</li>';
                }
                else
                    $code = '';

                // Now we open a new tag.
                $open_tags[] = array(
                    'tag' => 'li',
                    'after' => '</li>',
                    'trim' => 'outside',
                    'block_level' => true,
                    'disallow_children' => isset($inside['disallow_children']) ? $inside['disallow_children'] : null,
                );

                // First, open the tag...
                $code .= '<li' . ($tag == '' ? '' : ' type="' . $tag . '"') . '>';
                $message = substr($message, 0, $pos) . "\n" . $code . "\n" . substr($message, $pos + 3);
                $pos += strlen($code) - 1 + 2;

                // Next, find the next break (if any.)  If there's more itemcode after it, keep it going - otherwise close!
                $pos2 = strpos($message, '<br>', $pos);
                $pos3 = strpos($message, '[/', $pos);
                if ($pos2 !== false && ($pos2 <= $pos3 || $pos3 === false))
                {
                    preg_match('~^(<br>|&nbsp;|\s|\[)+~', substr($message, $pos2 + 6), $matches);
                    $message = substr($message, 0, $pos2) . (!empty($matches[0]) && substr($matches[0], -1) == '[' ? '[/li]' : '[/li][/list]') . substr($message, $pos2);

                    $open_tags[count($open_tags) - 2]['after'] = '</ul>';
                }
                // Tell the [list] that it needs to close specially.
                else
                {
                    // Move the li over, because we're not sure what we'll hit.
                    $open_tags[count($open_tags) - 1]['after'] = '';
                    $open_tags[count($open_tags) - 2]['after'] = '</li></ul>';
                }

                continue;
            }

            // Implicitly close lists and tables if something other than what's required is in them.  This is needed for itemcode.
            if ($tag === null && $inside !== null && !empty($inside['require_children']))
            {
                array_pop($open_tags);

                $message = substr($message, 0, $pos) . "\n" . $inside['after'] . "\n" . substr($message, $pos);
                $pos += strlen($inside['after']) - 1 + 2;
            }

            // No tag?  Keep looking, then.  Silly people using brackets without actual tags.
            if ($tag === null)
                continue;

            // Propagate the list to the child (so wrapping the disallowed tag won't work either.)
            if (isset($inside['disallow_children']))
                $tag['disallow_children'] = isset($tag['disallow_children']) ? array_unique(array_merge($tag['disallow_children'], $inside['disallow_children'])) : $inside['disallow_children'];

            // Is this tag disabled?
            if (isset($disabled[$tag['tag']]))
            {
                if (!isset($tag['disabled_before']) && !isset($tag['disabled_after']) && !isset($tag['disabled_content']))
                {
                    $tag['before'] = !empty($tag['block_level']) ? '<div>' : '';
                    $tag['after'] = !empty($tag['block_level']) ? '</div>' : '';
                    $tag['content'] = isset($tag['type']) && $tag['type'] == 'closed' ? '' : (!empty($tag['block_level']) ? '<div>$1</div>' : '$1');
                }
                elseif (isset($tag['disabled_before']) || isset($tag['disabled_after']))
                {
                    $tag['before'] = isset($tag['disabled_before']) ? $tag['disabled_before'] : (!empty($tag['block_level']) ? '<div>' : '');
                    $tag['after'] = isset($tag['disabled_after']) ? $tag['disabled_after'] : (!empty($tag['block_level']) ? '</div>' : '');
                }
                else
                    $tag['content'] = $tag['disabled_content'];
            }

            // we use this a lot
            $tag_strlen = strlen($tag['tag']);

            // The only special case is 'html', which doesn't need to close things.
            if (!empty($tag['block_level']) && $tag['tag'] != 'html' && empty($inside['block_level']))
            {
                $n = count($open_tags) - 1;
                while (empty($open_tags[$n]['block_level']) && $n >= 0)
                    $n--;

                // Close all the non block level tags so this tag isn't surrounded by them.
                for ($i = count($open_tags) - 1; $i > $n; $i--)
                {
                    $message = substr($message, 0, $pos) . "\n" . $open_tags[$i]['after'] . "\n" . substr($message, $pos);
                    $ot_strlen = strlen($open_tags[$i]['after']);
                    $pos += $ot_strlen + 2;
                    $pos1 += $ot_strlen + 2;

                    // Trim or eat trailing stuff... see comment at the end of the big loop.
                    if (!empty($open_tags[$i]['block_level']) && substr($message, $pos, 6) == '<br>')
                        $message = substr($message, 0, $pos) . substr($message, $pos + 6);
                    if (!empty($open_tags[$i]['trim']) && $tag['trim'] != 'inside' && preg_match('~(<br>|&nbsp;|\s)*~', substr($message, $pos), $matches) != 0)
                        $message = substr($message, 0, $pos) . substr($message, $pos + strlen($matches[0]));

                    array_pop($open_tags);
                }
            }

            // No type means 'parsed_content'.
            if (!isset($tag['type']))
            {
                // @todo Check for end tag first, so people can say "I like that [i] tag"?
                $open_tags[] = $tag;
                $message = substr($message, 0, $pos) . "\n" . $tag['before'] . "\n" . substr($message, $pos1);
                $pos += strlen($tag['before']) - 1 + 2;
            }
            // Don't parse the content, just skip it.
            elseif ($tag['type'] == 'unparsed_content')
            {
                $pos2 = stripos($message, '[/' . substr($message, $pos + 1, $tag_strlen) . ']', $pos1);
                if ($pos2 === false)
                    continue;

                $data = substr($message, $pos1, $pos2 - $pos1);

                if (!empty($tag['block_level']) && substr($data, 0, 6) == '<br>')
                    $data = substr($data, 6);

                if (isset($tag['validate']))
                    $tag['validate']($tag, $data, $disabled);

                $code = strtr($tag['content'], array('$1' => $data));
                $message = substr($message, 0, $pos) . "\n" . $code . "\n" . substr($message, $pos2 + 3 + $tag_strlen);

                $pos += strlen($code) - 1 + 2;
                $last_pos = $pos + 1;

            }
            // Don't parse the content, just skip it.
            elseif ($tag['type'] == 'unparsed_equals_content')
            {
                // The value may be quoted for some tags - check.
                if (isset($tag['quoted']))
                {
                    $quoted = substr($message, $pos1, 6) == '&quot;';
                    if ($tag['quoted'] != 'optional' && !$quoted)
                        continue;

                    if ($quoted)
                        $pos1 += 6;
                }
                else
                    $quoted = false;

                $pos2 = strpos($message, $quoted == false ? ']' : '&quot;]', $pos1);
                if ($pos2 === false)
                    continue;

                $pos3 = stripos($message, '[/' . substr($message, $pos + 1, $tag_strlen) . ']', $pos2);
                if ($pos3 === false)
                    continue;

                $data = array(
                    substr($message, $pos2 + ($quoted == false ? 1 : 7), $pos3 - ($pos2 + ($quoted == false ? 1 : 7))),
                    substr($message, $pos1, $pos2 - $pos1)
                );

                if (!empty($tag['block_level']) && substr($data[0], 0, 6) == '<br>')
                    $data[0] = substr($data[0], 6);

                // Validation for my parking, please!
                if (isset($tag['validate']))
                    $tag['validate']($tag, $data, $disabled);

                $code = strtr($tag['content'], array('$1' => $data[0], '$2' => $data[1]));
                $message = substr($message, 0, $pos) . "\n" . $code . "\n" . substr($message, $pos3 + 3 + $tag_strlen);
                $pos += strlen($code) - 1 + 2;
            }
            // A closed tag, with no content or value.
            elseif ($tag['type'] == 'closed')
            {
                $pos2 = strpos($message, ']', $pos);
                $message = substr($message, 0, $pos) . "\n" . $tag['content'] . "\n" . substr($message, $pos2 + 1);
                $pos += strlen($tag['content']) - 1 + 2;
            }
            // This one is sorta ugly... :/.  Unfortunately, it's needed for flash.
            elseif ($tag['type'] == 'unparsed_commas_content')
            {
                $pos2 = strpos($message, ']', $pos1);
                if ($pos2 === false)
                    continue;

                $pos3 = stripos($message, '[/' . substr($message, $pos + 1, $tag_strlen) . ']', $pos2);
                if ($pos3 === false)
                    continue;

                // We want $1 to be the content, and the rest to be csv.
                $data = explode(',', ',' . substr($message, $pos1, $pos2 - $pos1));
                $data[0] = substr($message, $pos2 + 1, $pos3 - $pos2 - 1);

                if (isset($tag['validate']))
                    $tag['validate']($tag, $data, $disabled);

                $code = $tag['content'];
                foreach ($data as $k => $d)
                    $code = strtr($code, array('$' . ($k + 1) => trim($d)));
                $message = substr($message, 0, $pos) . "\n" . $code . "\n" . substr($message, $pos3 + 3 + $tag_strlen);
                $pos += strlen($code) - 1 + 2;
            }
            // This has parsed content, and a csv value which is unparsed.
            elseif ($tag['type'] == 'unparsed_commas')
            {
                $pos2 = strpos($message, ']', $pos1);
                if ($pos2 === false)
                    continue;

                $data = explode(',', substr($message, $pos1, $pos2 - $pos1));

                if (isset($tag['validate']))
                    $tag['validate']($tag, $data, $disabled);

                // Fix after, for disabled code mainly.
                foreach ($data as $k => $d)
                    $tag['after'] = strtr($tag['after'], array('$' . ($k + 1) => trim($d)));

                $open_tags[] = $tag;

                // Replace them out, $1, $2, $3, $4, etc.
                $code = $tag['before'];
                foreach ($data as $k => $d)
                    $code = strtr($code, array('$' . ($k + 1) => trim($d)));
                $message = substr($message, 0, $pos) . "\n" . $code . "\n" . substr($message, $pos2 + 1);
                $pos += strlen($code) - 1 + 2;
            }
            // A tag set to a value, parsed or not.
            elseif ($tag['type'] == 'unparsed_equals' || $tag['type'] == 'parsed_equals')
            {
                // The value may be quoted for some tags - check.
                if (isset($tag['quoted']))
                {
                    $quoted = substr($message, $pos1, 6) == '&quot;';
                    if ($tag['quoted'] != 'optional' && !$quoted)
                        continue;

                    if ($quoted)
                        $pos1 += 6;
                }
                else
                    $quoted = false;

                $pos2 = strpos($message, $quoted == false ? ']' : '&quot;]', $pos1);
                if ($pos2 === false)
                    continue;

                $data = substr($message, $pos1, $pos2 - $pos1);

                // Validation for my parking, please!
                if (isset($tag['validate']))
                    $tag['validate']($tag, $data, $disabled);

                // For parsed content, we must recurse to avoid security problems.
                if ($tag['type'] != 'unparsed_equals')
                    $data = parse_bbc($data, !empty($tag['parsed_tags_allowed']) ? false : true, '', !empty($tag['parsed_tags_allowed']) ? $tag['parsed_tags_allowed'] : array());

                $tag['after'] = strtr($tag['after'], array('$1' => $data));

                $open_tags[] = $tag;

                $code = strtr($tag['before'], array('$1' => $data));
                $message = substr($message, 0, $pos) . "\n" . $code . "\n" . substr($message, $pos2 + ($quoted == false ? 1 : 7));
                $pos += strlen($code) - 1 + 2;
            }

            // If this is block level, eat any breaks after it.
            if (!empty($tag['block_level']) && substr($message, $pos + 1, 6) == '<br>')
                $message = substr($message, 0, $pos + 1) . substr($message, $pos + 7);

            // Are we trimming outside this tag?
            if (!empty($tag['trim']) && $tag['trim'] != 'outside' && preg_match('~(<br>|&nbsp;|\s)*~', substr($message, $pos + 1), $matches) != 0)
                $message = substr($message, 0, $pos + 1) . substr($message, $pos + 1 + strlen($matches[0]));
        }

        // Close any remaining tags.
        while ($tag = array_pop($open_tags))
            $message .= "\n" . $tag['after'] . "\n";

        $message = strtr($message, array("\n" => ''));

        if ($message[0] === ' ')
            $message = '&nbsp;' . substr($message, 1);

        // Cleanup whitespace.
        $message = strtr($message, array('  ' => ' &nbsp;', "\r" => '', "\n" => '<br>', '<br> ' => '<br>&nbsp;', '&#13;' => "\n"));

        return $message;
    }

    // Adopted from the original SMF2.1 source
    private static function permute($array)
    {
        $orders = array($array);
        $n = count($array);
        $p = range(0, $n);
        for ($i = 1; $i < $n; null)
        {
            $p[$i]--;
            $j = $i % 2 != 0 ? $p[$i] : 0;
            $temp = $array[$i];
            $array[$i] = $array[$j];
            $array[$j] = $temp;
            for ($i = 1; $p[$i] == 0; $i++)
                $p[$i] = 1;
            $orders[] = $array;
        }
        return $orders;
    }
}
