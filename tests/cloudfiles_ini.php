<?php
$VERBOSE = True;                        # informational messages to stdout
$USER    = "Username";                  # Mosso Username
$API_KEY = "API Key";                   # User's API Access Key
$ACCOUNT = NULL;                        # DEPRECATED: account name
$HOST    = NULL;                        # DEPRECATED: authentication host URL

# Allow override by environment variable
if (isset($_ENV["MOSSO_API_USER"])) {
    $USER = $_ENV["MOSSO_API_USER"];
}

if (isset($_ENV["MOSSO_API_KEY"])) {
    $API_KEY = $_ENV["MOSSO_API_KEY"];
}

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
?>
