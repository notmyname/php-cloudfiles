<?php
# common test utility functions
#

# re-implementation of PECL's http_date
#
function httpDate($ts=NULL)
{
    if (!$ts) {
        return gmdate("D, j M Y h:i:s T");
    } else {
        return gmdate("D, j M Y h:i:s T", $ts);
    }
}

# Specify a word length and any characters to exlude and return
# a valid UTF-8 string (within the ASCII range)
#
function genUTF8($len=10, $excludes=array())
{
    $r = "";
    while (strlen($r) < $len) {
        $c = rand(32,127); # chr() only works with ASCII (0-127)
        if (in_array($c, $excludes)) { continue; }
        $r .= chr($c); # chr() only works with ASCII (0-127)
    }
    return utf8_encode($r);
}

# generate a big string
#
function big_string($length)
{
    $r = array();
    for ($i=0; $i < $length; $i++) {
        $r[] = "a";
    }
    return join("", $r);
}

?>
