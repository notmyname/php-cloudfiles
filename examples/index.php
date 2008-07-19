<?php
require("capon.php");

$CFS_ACCOUNT = "Your CloudFS Account Name";
$CFS_USERNAME = "Your CloudFS Username";
$CFS_PASSWORD = "Your CloudFS Password";
$CFS_AUTH_URL = "The CloudFS Authentication URL"

$CFS_CONTAINER = "CloudFS Image Container";

# Authenticate to CloudFS
#
$auth = new CLOUDFS_Authentication($CFS_ACCOUNT,
        $CFS_USERNAME,$CFS_PASSWORD,$CFS_AUTH_URL);
$auth->authenticate();

# Connect to CloudFS after authentication
#
$conn = new CLOUDFS_Connection($auth);

# Grab reference to container
#
$container = $conn->get_container($CFS_CONTAINER);


if ($_GET["display"]) {
    # Display the requested image or throw a 404 if that fails
    #
    try {
        $obj = $container->get_object($_GET["display"]);
        header("Content-Type: " . $obj->content_type);
        $fp = fopen("php://output", "w");
        $obj->stream($fp);
        fclose($fp);
    } catch (Exception $e) {
        header("HTTP/1.1 404 Not Found");
        header("Content-Type: text/html; charset=ISO-8859-1");
        print "<html>\n<body>\n";
        print "<h2>File not found.</h2>";
        print "<a href='/'>Go Back</a>\n";
        print "</body>\n</html>";
    }

} else {
    # List out the stored images and create a link to display them
    # if the user clicks on a link.
    #
    print "Click an image link to display it in your browser.<br />\n";
    print "<ul>\n";
    $object_list = $container->list_objects();
    foreach ($object_list as $obj) {
        print "<li> <a href='/?display=".$obj."'>".$obj."</a></li>\n";
    }
    print "</ul>\n";
}

?>
