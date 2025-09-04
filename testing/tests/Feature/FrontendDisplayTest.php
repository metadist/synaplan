<?php

use PHPUnit\Framework\TestCase;

/**
 * Frontend Display Tests
 * 
 * Tests that verify the frontend displays correctly and all pages load
 */
class FrontendDisplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup environment for frontend testing
        $GLOBALS["debug"] = false; // Disable debug for clean output
        $GLOBALS["baseUrl"] = "https://test.synaplan.com/";
        
        // Start clean session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    public function testIndexPageLoads()
    {
        // Capture output from index.php
        ob_start();
        
        // Mock the environment for index.php
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        
        // Include index.php and capture output
        try {
            include __DIR__ . '/../../../public/index.php';
            $output = ob_get_contents();
        } catch (Exception $e) {
            $output = '';
        } finally {
            ob_end_clean();
        }
        
        // Verify HTML structure
        $this->assertStringContainsString('<!doctype html>', $output);
        $this->assertStringContainsString('<title>synaplan - digital thinking</title>', $output);
        $this->assertStringContainsString('bootstrap.min.css', $output);
        $this->assertStringContainsString('dashboard.js', $output);
    }
    
    public function testChatInterfaceElements()
    {
        // Setup authenticated user session
        $_SESSION["USERPROFILE"] = [
            "BID" => 12345,
            "BMAIL" => "test@example.com"
        ];
        
        // Capture chat snippet output
        ob_start();
        
        try {
            include __DIR__ . '/../../../public/snippets/c_chat.php';
            $output = ob_get_contents();
        } catch (Exception $e) {
            $output = '';
        } finally {
            ob_end_clean();
        }
        
        // Verify chat interface elements
        $this->assertStringContainsString('chat-container', $output);
        $this->assertStringContainsString('chat-messages', $output);
        $this->assertStringContainsString('chatModalBody', $output);
        $this->assertStringContainsString('load-history-btn', $output);
    }
    
    public function testWidgetInterfaceElements()
    {
        // Setup authenticated user session
        $_SESSION["USERPROFILE"] = [
            "BID" => 12345,
            "BMAIL" => "test@example.com"
        ];
        
        // Capture widget snippet output
        ob_start();
        
        try {
            include __DIR__ . '/../../../public/snippets/c_webwidget.php';
            $output = ob_get_contents();
        } catch (Exception $e) {
            $output = '';
        } finally {
            ob_end_clean();
        }
        
        // Verify widget interface elements
        $this->assertStringContainsString('widget', $output);
        $this->assertStringContainsString('webwidgetForm', $output);
    }
    
    public function testApiKeysInterfaceElements()
    {
        // Setup authenticated user session
        $_SESSION["USERPROFILE"] = [
            "BID" => 12345,
            "BMAIL" => "test@example.com"
        ];
        
        // Capture API keys snippet output
        ob_start();
        
        try {
            include __DIR__ . '/../../../public/snippets/c_apikeys.php';
            $output = ob_get_contents();
        } catch (Exception $e) {
            $output = '';
        } finally {
            ob_end_clean();
        }
        
        // Verify API keys interface elements
        $this->assertStringContainsString('API Keys', $output);
        $this->assertStringContainsString('createApiKeyForm', $output);
    }
    
    public function testRegistrationFormElements()
    {
        // Capture registration snippet output
        ob_start();
        
        try {
            include __DIR__ . '/../../../public/snippets/c_register.php';
            $output = ob_get_contents();
        } catch (Exception $e) {
            $output = '';
        } finally {
            ob_end_clean();
        }
        
        // Verify registration form elements
        $this->assertStringContainsString('registrationForm', $output);
        $this->assertStringContainsString('email', $output);
        $this->assertStringContainsString('password', $output);
        $this->assertStringContainsString('confirmPassword', $output);
    }
    
    public function testDashboardStatisticsDisplay()
    {
        // Setup authenticated user session
        $_SESSION["USERPROFILE"] = [
            "BID" => 12345,
            "BMAIL" => "test@example.com"
        ];
        
        // Capture statistics snippet output
        ob_start();
        
        try {
            include __DIR__ . '/../../../public/snippets/c_statistics.php';
            $output = ob_get_contents();
        } catch (Exception $e) {
            $output = '';
        } finally {
            ob_end_clean();
        }
        
        // Verify statistics display elements
        $this->assertStringContainsString('Message Statistics', $output);
        $this->assertStringContainsString('File Statistics', $output);
        $this->assertStringContainsString('Recent Files', $output);
    }
    
    public function testResponsiveDesignElements()
    {
        // Test that responsive classes are present
        ob_start();
        
        try {
            include __DIR__ . '/../../../public/index.php';
            $output = ob_get_contents();
        } catch (Exception $e) {
            $output = '';
        } finally {
            ob_end_clean();
        }
        
        // Check for Bootstrap responsive classes
        $this->assertStringContainsString('container-fluid', $output);
        $this->assertStringContainsString('col-md-', $output);
        $this->assertStringContainsString('viewport', $output);
        $this->assertStringContainsString('bootstrap.min.css', $output);
    }
    
    public function testJavaScriptInclusions()
    {
        ob_start();
        
        try {
            include __DIR__ . '/../../../public/index.php';
            $output = ob_get_contents();
        } catch (Exception $e) {
            $output = '';
        } finally {
            ob_end_clean();
        }
        
        // Verify JavaScript files are included
        $this->assertStringContainsString('jquery.min.js', $output);
        $this->assertStringContainsString('bootstrap.bundle.min.js', $output);
        $this->assertStringContainsString('dashboard.js', $output);
        $this->assertStringContainsString('feather.min.js', $output);
    }
}
