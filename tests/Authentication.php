<?php
require_once 'PHPUnit/Framework.php';
require_once 'common.php';

class CloudFileAuthTest extends PHPUnit_Framework_TestCase
{
    function __construct() {
        $this->windows = (strtoupper (substr(PHP_OS, 0,3)) == 'WIN' ) ? true : false;
        $this->auth = null;
    }

    protected function setUp() {
        $this->auth = new CF_Authentication(USER, API_KEY);
        $this->auth->authenticate();
        if ($this->windows)
            $this->auth->ssl_use_cabundle();
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