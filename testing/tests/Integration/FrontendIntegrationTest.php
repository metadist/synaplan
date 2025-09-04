<?php

require_once __DIR__ . '/../Helpers/TestDatabaseHelper.php';

use PHPUnit\Framework\TestCase;

/**
 * Integration Tests for Frontend functionality
 */
class FrontendIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup test database
        TestDatabaseHelper::setupTestDatabase();
        
        // Setup globals
        $GLOBALS["debug"] = true;
        $GLOBALS["baseUrl"] = "https://test.synaplan.com/";
        
        // Mock Core includes
        if (!class_exists('Frontend')) {
            require_once __DIR__ . '/../../../public/inc/_frontend.php';
        }
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        
        TestDatabaseHelper::cleanupTestDatabase();
        unset($_SESSION["USERPROFILE"]);
        $_REQUEST = [];
    }
    
    public function testUserAuthenticationFlow()
    {
        // Test login with valid credentials
        $_REQUEST = [
            'email' => 'test@example.com',
            'password' => 'password123'
        ];
        
        $result = Frontend::setUserFromWebLogin();
        $this->assertTrue($result);
        $this->assertArrayHasKey('USERPROFILE', $_SESSION);
        
        // Test profile retrieval after login
        $profile = Frontend::getProfile();
        $this->assertTrue($profile['success']);
        $this->assertEquals('test@example.com', $profile['BMAIL']);
    }
    
    public function testChatHistoryFunctionality()
    {
        // Setup user session
        $_SESSION["USERPROFILE"]["BID"] = 12345;
        
        // Test chat history loading
        $result = Frontend::loadChatHistory(10);
        
        $this->assertTrue($result['success']);
        $this->assertIsArray($result['messages']);
        $this->assertEquals(10, $result['amount']);
    }
    
    public function testDashboardStats()
    {
        // Setup user session
        $_SESSION["USERPROFILE"]["BID"] = 12345;
        
        $stats = Frontend::getDashboardStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_messages', $stats);
        $this->assertArrayHasKey('messages_sent', $stats);
        $this->assertArrayHasKey('messages_received', $stats);
        $this->assertArrayHasKey('total_files', $stats);
    }
    
    public function testWidgetManagement()
    {
        // Setup user session
        $_SESSION["USERPROFILE"]["BID"] = 12345;
        
        // Test getting widgets
        $result = Frontend::getWidgets();
        $this->assertTrue($result['success']);
        $this->assertIsArray($result['widgets']);
        
        // Test saving widget
        $_REQUEST = [
            'widgetId' => 1,
            'widgetColor' => '#FF0000',
            'widgetPosition' => 'bottom-right',
            'autoMessage' => 'Hello from widget!',
            'widgetPrompt' => 'general'
        ];
        
        $result = Frontend::saveWidget();
        $this->assertTrue($result['success']);
        
        // Test deleting widget
        $_REQUEST = ['widgetId' => 1];
        $result = Frontend::deleteWidget();
        $this->assertTrue($result['success']);
    }
    
    public function testAnonymousWidgetSessionManagement()
    {
        // Test setting anonymous widget session
        $result = Frontend::setAnonymousWidgetSession(12345, 1);
        $this->assertTrue($result);
        
        // Verify session variables are set
        $this->assertTrue($_SESSION["is_widget"]);
        $this->assertEquals(12345, $_SESSION["widget_owner_id"]);
        $this->assertEquals(1, $_SESSION["widget_id"]);
        $this->assertNotEmpty($_SESSION["anonymous_session_id"]);
        
        // Test session validation
        $result = Frontend::validateAnonymousSession();
        $this->assertTrue($result);
        
        // Test invalid widget parameters
        $result = Frontend::setAnonymousWidgetSession(0, 1);
        $this->assertFalse($result);
        
        $result = Frontend::setAnonymousWidgetSession(12345, 0);
        $this->assertFalse($result);
        
        $result = Frontend::setAnonymousWidgetSession(12345, 10);
        $this->assertFalse($result);
    }
}
