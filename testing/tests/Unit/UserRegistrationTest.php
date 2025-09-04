<?php

require_once __DIR__ . '/../../../public/inc/services/EmailService.php';
require_once __DIR__ . '/../../../public/inc/auth/UserRegistration.php';

use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for UserRegistration
 */
class UserRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up test session
        $_SESSION["LANG"] = "en";
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up
        $_REQUEST = [];
        unset($_SESSION["LANG"]);
    }

    public function testRegisterNewUserWithEmptyEmail()
    {
        $_REQUEST['email'] = '';
        $_REQUEST['password'] = 'password123';
        $_REQUEST['confirmPassword'] = 'password123';
        
        $result = UserRegistration::registerNewUser();
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Email address is required.', $result['error']);
    }

    public function testRegisterNewUserWithEmptyPassword()
    {
        $_REQUEST['email'] = 'test@example.com';
        $_REQUEST['password'] = '';
        $_REQUEST['confirmPassword'] = '';
        
        $result = UserRegistration::registerNewUser();
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Password is required.', $result['error']);
    }

    public function testRegisterNewUserWithMismatchedPasswords()
    {
        $_REQUEST['email'] = 'test@example.com';
        $_REQUEST['password'] = 'password123';
        $_REQUEST['confirmPassword'] = 'different123';
        
        $result = UserRegistration::registerNewUser();
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Passwords do not match.', $result['error']);
    }

    public function testRegisterNewUserWithShortPassword()
    {
        $_REQUEST['email'] = 'test@example.com';
        $_REQUEST['password'] = '123';
        $_REQUEST['confirmPassword'] = '123';
        
        $result = UserRegistration::registerNewUser();
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Password must be at least 6 characters long.', $result['error']);
    }

    public function testGeneratePinLength()
    {
        // Use reflection to access private method
        $reflection = new ReflectionClass('UserRegistration');
        $method = $reflection->getMethod('generatePin');
        $method->setAccessible(true);
        
        $pin = $method->invoke(null);
        
        $this->assertEquals(6, strlen($pin));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $pin);
    }

    public function testGeneratePinUniqueness()
    {
        $reflection = new ReflectionClass('UserRegistration');
        $method = $reflection->getMethod('generatePin');
        $method->setAccessible(true);
        
        $pins = [];
        for ($i = 0; $i < 100; $i++) {
            $pins[] = $method->invoke(null);
        }
        
        // All PINs should be unique (very high probability)
        $uniquePins = array_unique($pins);
        $this->assertGreaterThan(90, count($uniquePins)); // Allow for small chance of collision
    }
}
