<?php
// -----------------------------------------------------
// Inbound configuration
// -----------------------------------------------------

require_once __DIR__ . "/../app/inc/api/_inboundconf.php";
?>
<link rel="stylesheet" href="assets/statics/fa/css/all.min.css">
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <h1><?php _s("Inbound", __FILE__, $_SESSION["LANG"]); ?></h1>
    <p>
        <?php _s("You can reach this platform via different channels.", __FILE__, $_SESSION["LANG"]); ?><br>
        <?php _s("Different channels offer different features, if you set those up.", __FILE__, $_SESSION["LANG"]); ?><br>
        <?php _s("Please take a look at the channels listed below:", __FILE__, $_SESSION["LANG"]); ?><br>
    </p>

    <!-- WhatsApp Channel Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fab fa-whatsapp me-1"></i> WhatsApp Channel(s)</h5>
        </div>
        <div class="card-body">
            <?php
            $numArr = InboundConf::getWhatsAppNumbers();
            foreach($numArr as $num) {
                print "+".$num["BWAOUTNUMBER"].": default handling<br>";
            }
            ?>
            <!-- Add your WhatsApp channel form here if needed -->
        </div>
    </div>

    <!-- Email Channel Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-envelope"></i> Email Channel(s)</h5>
        </div>
        <div class="card-body">
            <a href="mailto:smart@ralfs.ai">smart@synaplan.com</a>: default handling<br>
            <form action="index.php/inbound" method="post" class="mt-2">
                <label for="keyword" class="form-label">Check for a free keyword to add your own handling:</label>
                <div class="input-group mb-2">
                    <span class="input-group-text">smart+</span>
                    <input type="text" name="keyword" id="keyword" class="form-control" placeholder="keyword">
                    <span class="input-group-text">@synaplan.com</span>
                </div>
                <button type="submit" class="btn btn-outline-primary">Save keyword</button>
            </form>
        </div>
    </div>

    <!-- Repeat the card pattern for API Channel and Web Widget below -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-code"></i> API Channel</h5>
        </div>
        <div class="card-body">
            Simple API calls with your personal API key:<br>
            https://synawork.com/api.php<br><br>
            Example:<br><br>
            <code>
                curl -X POST https://synawork.com/api.php \<br>
                -H "Authorization: Bearer YOUR_API_KEY" \<br>
                -H "Content-Type: application/json" \<br>
                -d '{"number": "1234567890", "message": "Hello, world!"}'<br>
            </code>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-window-maximize"></i> Web Widget</h5>
        </div>
        <div class="card-body">
            <strong>To activate the widget, please enter your domain name like "yourdomain.net" in the field below.</strong>
            <BR>
             
            It is hidden by default, until the collapse plugin adds the appropriate classes that we use to style each element. 
            These classes control the overall appearance, as well as the showing and hiding via CSS transitions. 
        </div>
    </div>
</main>