<?
require("cloudfs_auth.php");
require("capon.php");

$DBUG = True;
$PROD = False;
$TEST_CONTAINER = "The Capon";
$TEST_OBJECT = "The Object";
$TEST_OBJECT2 = "Filestream_object";
$TEST_OBJECT3 = "fullpath_object";

#$BIGGIE = "biggie_object";
#$BIG_FILE = "/home/ej/bigfile.dat";

$SINK_FILE = "/tmp/foo.txt";
$TOBJECTS = array(
    $TEST_OBJECT,
    $TEST_OBJECT2,
    $TEST_OBJECT3,
#    $BIGGIE,
    );

if ($PROD) {
    $account = "Racklabs";
    $username = "ej";
    $password = "ejtest";
    $auth_url = "https://auth.clouddrive.com";
} else {
    $account = "PicsWall";
    $username = "pics";
    $password = "picswall";
    $auth_url = "http://auth.stg.racklabs.com";
}

$auth = new CLOUDFS_Authentication($account, $username, $password, $auth_url);
#$auth->dbug = True;
if (!$auth->authenticate()) {
    print "Failed to authenticate\n";
    print " +-> Status code: " . $auth->error_status_code . "\n";
    print " +-> Status reason: " . $auth->error_status_reason . "\n";
    exit();
}
$storage_url = $auth->storage_url;
$storage_token = $auth->storage_token;
$storage_account = $auth->storage_account;

if ($DBUG) {
    print "Authenticated:\n";
    print " -> storage account: " . $storage_account . "\n";
    print " -> storage url: " . $storage_url . "\n";
    print " -> storage token: " . $storage_token . "\n";
}


$conn = new CLOUDFS_Connection($storage_url, $storage_token, $storage_account);
#$conn->setDebug(True);

print "== list containers ==================================\n";
$result = $conn->get_containers();
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print_r($result);
}

print "== make container ===================================\n";
#$conn->setDebug(True);
$result = $conn->create_container($TEST_CONTAINER);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print "SUCCESS: '".$TEST_CONTAINER."' container created.\n";
}

print "== check container ==================================\n";
#$conn->setDebug(True);
$result = $conn->check_container($TEST_CONTAINER);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print_r($result);
}
#$conn->setDebug(False);

print "== check container2 =================================\n";
#$conn->setDebug(True);
$result = $conn->check_container($TEST_CONTAINER);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print_r($result);
}

print "== create object from string ========================\n";
#$conn->setDebug(False);
$data = "This is the data to write.";
$size = strlen($data);
$etag = md5($data);
$result = $conn->create_object(
    $TEST_CONTAINER, $TEST_OBJECT,
    array(
        "foo" => "This is foo",
        "bar" => "This is bar",
    ),
    $data,
    "text/plain",
    $size,
    $etag
    );
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print "SUCCESS: ".$TEST_CONTAINER."/".$TEST_OBJECT." object created.\n";
}

print "== create object from file stream ===================\n";
#$conn->setDebug(True);
$fp = fopen("tests/test_capon.php", "r");
$etag = md5_file("tests/test_capon.php");
$size = filesize("tests/test_capon.php");
$result = $conn->create_object(
    $TEST_CONTAINER, $TEST_OBJECT2,
    array(
        "wife" => "Julie Johnson",
        "son" => "Tate Johnson",
    ),
    $fp,
    "text/plain",
    $size,
    $etag
    );
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print "SUCCESS: ".$TEST_CONTAINER."/".$TEST_OBJECT2." object created.\n";
}
fclose($fp);


print "== create object from full filename path ============\n";
#$conn->setDebug(True);
$result = $conn->upload_filename(
    $TEST_CONTAINER, $TEST_OBJECT3, "tests/test_capon.php",
    array(
        "Bike" => "2008 FXDWG",
    )
    );
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print "SUCCESS: ".$TEST_CONTAINER."/".$TEST_OBJECT3." object created.\n";
}

if ($BIG_FILE) {
    print "== create object from BIG_FILE ======================\n";
    $conn->setDebug(True);
    $result = $conn->upload_filename(
        $TEST_CONTAINER, $BIGGIE, $BIG_FILE,
        array(
            "Bike" => "2008 FXDWG",
        )
        );
    if (!$result) {
        print "ERROR: " . $conn->get_error() . "\n";
    } else {
        print "SUCCESS: ".$TEST_CONTAINER."/".$TEST_OBJECT3." object created.\n";
    }
    $conn->setDebug(False);
}

print "== list objects =====================================\n";
#$conn->setDebug(True);
$result = $conn->get_objects($TEST_CONTAINER);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print_r($result);
}

print "== list objects, limit=2 ============================\n";
$result = $conn->get_objects($TEST_CONTAINER, 2);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print_r($result);
}

print "== list objects, prefix='full' ======================\n";
$result = $conn->get_objects($TEST_CONTAINER, 0, -1, "full");
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print_r($result);
}

print "== check object =====================================\n";
#$conn->setDebug(True);
$result = $conn->check_object($TEST_CONTAINER, $TEST_OBJECT);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print_r($result);
}

print "== get object to string =============================\n";
#$conn->setDebug(True);
$result = $conn->get_object($TEST_CONTAINER, $TEST_OBJECT);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print "OBJ DATA: " . $conn->object_write_string . "\n";
}

print "== get object to stream =============================\n";
#$conn->setDebug(True);
$fp = fopen($SINK_FILE, "w");
$result = $conn->get_object($TEST_CONTAINER, $TEST_OBJECT2, $fp);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
}
fclose($fp);
$data = file_get_contents($SINK_FILE);
print "FILE DATA LENGTH: " . strlen($data) . "\n";

print "== download object to file ==========================\n";
#$conn->setDebug(True);
$result = $conn->download_filename($TEST_CONTAINER, $TEST_OBJECT3, $SINK_FILE);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
}
$data = file_get_contents($SINK_FILE);
print "FILE DATA LENGTH: " . strlen($data) . "\n";


print "== update object metadata ===========================\n";
#$conn->setDebug(True);
$md = array(
    "Alpha" => "Letter A",
    "Beta" => "Letter B",
);
$result = $conn->update_object($TEST_CONTAINER, $TEST_OBJECT, $md);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print "SUCCESS UPDATING OBJECT METADATA\n";
}

print "== check object =====================================\n";
#$conn->setDebug(True);
$result = $conn->check_object($TEST_CONTAINER, $TEST_OBJECT);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print_r($result);
}

print "== delete objects ===================================\n";
#$conn->setDebug(True);
foreach ($TOBJECTS as $k => $v) {
    $result = $conn->delete_object($TEST_CONTAINER, $v);
    if (!$result) {
        print "ERROR (".$v."): " . $conn->get_error() . "\n";
    } else {
        print "SUCCESS DELETING OBJECT '".$TEST_CONTAINER."/".$v."'\n";
    }
}

print "== check container ==================================\n";
#$conn->setDebug(True);
$result = $conn->check_container($TEST_CONTAINER);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print_r($result);
}

print "== delete container =================================\n";
#$conn->setDebug(True);
$result = $conn->delete_container($TEST_CONTAINER);
if (!$result) {
    print "ERROR (".$v."): " . $conn->get_error() . "\n";
} else {
    print "SUCCESS DELETING CONTAINER '".$TEST_CONTAINER."'\n";
}

print "== check container ==================================\n";
#$conn->setDebug(True);
$result = $conn->check_container($TEST_CONTAINER);
if (!$result) {
    print "ERROR: " . $conn->get_error() . "\n";
} else {
    print_r($result);
}

if ($DBUG ) {print "...DONE\n";}
# vim: ts=4 sw=4 sts=4 et:
?>
