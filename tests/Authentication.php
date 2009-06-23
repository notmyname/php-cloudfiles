<?php # -*- compile-command: (concat "phpunit " buffer-file-name) -*-
require_once 'PHPUnit/Framework.php';
require_once 'common.php';

class Authentication extends PHPUnit_Framework_TestCase
{
    function __construct() {
        $this->auth = null;
    }

    protected function setUp() {
        $this->auth = new CF_Authentication(USER, API_KEY);
        $this->auth->authenticate();
        $conn = new CF_Connection($this->auth);
    }    

    public function testAuthenticationWithoutUsernamePassword()
    {        
        $this->setExpectedException('SyntaxException');
        $auth = new CF_Authentication(NULL, NULL);
    }

    public function testBadAuthentication()
    {
        $this->setExpectedException('AuthenticationException');
        $auth = new CF_Authentication('e046e8db7d813050b14ce335f2511e83', 'bleurrhrhahra');
        $auth->authenticate();
    }
    
    public function testAuthenticationAttributes()
    {        
        $this->assertNotNull($this->auth->storage_url);
        $this->assertNotNull($this->auth->auth_token);

        if (ACCOUNT)
            $this->assertNotNull($this->auth->cdnm_url);
    }
}

?>