<!-- Top Navbar for mobile devices -->
<nav class="navbar navbar-expand-md d-md-none gradient-dots fixed-top" style="z-index: 1030;">
    <div class="container-fluid">
        <a class="navbar-brand" href="/">
            <img src="assets/statics/img/synaplan_logo_ondark.svg" alt="AI management" width="120">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
    </div>
</nav>

<!-- Sidebar -->
<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block gradient-dots sidebar collapse">
    <div class="position-sticky pt-2">
        <!-- Logo only (toggle moved to top navbar) -->
        <div class="px-3 py-2 mb-2 text-center d-none d-md-block">
            	<a class="navbar-brand" href="/"><img src="assets/statics/img/synaplan_logo_ondark.svg" alt="AI management" width="140"></a>
        </div>
        <hr style="border: 0; border-top: 1px solid #d1d5db; margin: 5px 10px 12px 10px;">
        <ul class="nav flex-column">
            <li class="nav-item">
            <a class="nav-link<?php ($contentInc=="chat" || $contentInc=="chat-history") ? print " active" : ""; ?>" href="index.php/chat">
                <span data-feather="message-square"></span>
                Chat
            </a>
            <?php if($contentInc == "chat" || $contentInc == "chat-history") { ?>
                <ul class="nav flex-column" style="margin-left: 18px;">
                    <li class="subitem"><a href="index.php/chat-history" id="chatHistoryMenu">History Log</a></li>
                </ul>
            <?php } ?>
            </li>
            <li class="nav-item">
            <a class="nav-link<?php ($contentInc=="tools") ? print " active" : ""; ?>" href="index.php/tools">
                <span data-feather="tool"></span>
                Tools
            </a>
            <?php if($contentInc == "tools" || $contentInc == "webwidget" || $contentInc == "docsummary" 
                || $contentInc == "soundstream" || $contentInc == "mailhandler") { ?>
                <ul class="nav flex-column" style="margin-left: 18px;">
                    <li class="subitem"><a href="index.php/webwidget" id="toolMenu1">Chat Widget</a></li>
                    <li class="subitem"><a href="index.php/docsummary" id="toolMenu2">Doc Summary</a></li>
                    <!-- li class="subitem"><a href="index.php/soundstream" id="toolMenu3">Sound2Text</a></li -->
                    <li class="subitem"><a href="index.php/mailhandler" id="toolMenu4">Mail Handler</a></li>
                </ul>
            <?php } ?>
            </li>
            <li class="nav-item">
            <a class="nav-link<?php ($contentInc=="filemanager") ? print " active" : ""; ?>" href="index.php/filemanager">
                <span data-feather="image"></span>
                Files &amp; RAG
            </a>
            </li>
            <li class="nav-item">
            <a class="nav-link<?php ($contentInc=="ais") ? print " active" : ""; ?>" href="index.php/ais">
                <span data-feather="cpu"></span>
                AI Config
            </a>
            <?php if($contentInc == "ais" || $contentInc == "inbound" || $contentInc == "preprocessor" 
                || $contentInc == "prompts" || $contentInc == "aimodels" || $contentInc == "outprocessor" || $contentInc == "apikeys") { ?>
                <ul class="nav flex-column" style="margin-left: 18px;">
                    <li class="subitem"><a href="index.php/inbound" id="menuPoint1">Inbound</a></li>
                    <li class="subitem"><a href="index.php/aimodels" id="menuPoint4">AI Models</a></li>
                    <li class="subitem"><a href="index.php/prompts" id="menuPoint3">Task Prompts</a></li>
                    <li class="subitem"><a href="index.php/preprocessor" id="menuPoint2">Sorting Prompt</a></li>
                    <li class="subitem"><a href="index.php/apikeys" id="menuPoint5">API Keys</a></li>
                    <!-- li class="subitem"><a href="index.php/outprocessor">Outbound</a></li -->
                </ul>
            <?php } ?>
            </li>
            <li class="nav-item">
            <a class="nav-link<?php ($contentInc=="statistics") ? print " active" : ""; ?>" aria-current="page" href="index.php/statistics">
                <span data-feather="bar-chart-2"></span>
                Statistics
            </a>
            </li>
        </ul>
    </div>
    
    <!-- Logout Section at Bottom -->
    <div class="logout-section">
            <div class="user-info" onclick="window.location.href='index.php/settings'" style="cursor: pointer;">
                <div class="user-avatar">
                    <div class="avatar-circle">
                        <?php echo isset($_SESSION["USERPROFILE"]["BMAIL"]) ? strtoupper(substr($_SESSION["USERPROFILE"]["BMAIL"], 0, 1)) : 'S'; ?>
                    </div>
                </div>
                <div class="user-details">
                    <div class="user-email">
                        <?php echo isset($_SESSION["USERPROFILE"]["BMAIL"]) ? $_SESSION["USERPROFILE"]["BMAIL"] : 'user@example.com'; ?>
                    </div>
                </div>
                <button class="logout-btn" onclick="event.stopPropagation(); showLogoutModal()" title="Logout">
                    <span data-feather="log-out"></span>
                </button>
            </div>
        </div>
</nav>