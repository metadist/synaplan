<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><?php _s("Available Tools", __FILE__, $_SESSION["LANG"]); ?></h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.href='index.php/chat'">
                    <i class="fas fa-comments"></i> Chat
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.href='index.php/prompts'">
                    <i class="fas fa-cog"></i> Prompts
                </button>
            </div>
        </div>
    </div>
    <div class="alert alert-warning" role="alert">
            <i class="fas fa-info-circle"></i>
            <?php _s("<B>Please note:</B> The MCP Server functionality is coming by end of August 2025. Stay tuned and use our tools directly.", __FILE__, $_SESSION["LANG"]); ?>
        </div>

    <div class="alert alert-info" role="alert">
        <i class="fas fa-info-circle"></i>
        <?php _s("Use these commands in the chat to access powerful tools and features. Simply type the command followed by your text or parameters.", __FILE__, $_SESSION["LANG"]); ?>
    </div>

    <div class="row">
        <!-- List Command -->
        <div class="col-lg-6 col-md-12 mb-4">
            <div class="card border h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list"></i> <?php _s("Command List", __FILE__, $_SESSION["LANG"]); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="flex-shrink-0 me-3">
                            <i class="fas fa-list fa-2x text-primary"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="card-title">*/list*</h6>
                            <p class="card-text"><?php _s("Shows this list of available commands and tools.", __FILE__, $_SESSION["LANG"]); ?></p>
                            <div class="mt-3">
                                <span class="badge bg-light text-dark"><?php _s("No parameters needed", __FILE__, $_SESSION["LANG"]); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Image Generation -->
        <div class="col-lg-6 col-md-12 mb-4">
            <div class="card border h-100">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-image"></i> <?php _s("Image Generation", __FILE__, $_SESSION["LANG"]); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="flex-shrink-0 me-3">
                            <i class="fas fa-image fa-2x text-success"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="card-title">*/pic [text]*</h6>
                            <p class="card-text"><?php _s("Creates an image from the provided text description using AI.", __FILE__, $_SESSION["LANG"]); ?></p>
                            <div class="mt-3">
                                <span class="badge bg-success text-white"><?php _s("AI Generated", __FILE__, $_SESSION["LANG"]); ?></span>
                                <span class="badge bg-light text-dark"><?php _s("Text to Image", __FILE__, $_SESSION["LANG"]); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Video Generation -->
        <div class="col-lg-6 col-md-12 mb-4">
            <div class="card border h-100">
                <div class="card-header bg-danger text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-video"></i> <?php _s("Video Generation", __FILE__, $_SESSION["LANG"]); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="flex-shrink-0 me-3">
                            <i class="fas fa-video fa-2x text-danger"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="card-title">*/vid [text]*</h6>
                            <p class="card-text"><?php _s("Creates a 7-8 seconds video from the provided text description.", __FILE__, $_SESSION["LANG"]); ?></p>
                            <div class="mt-3">
                                <span class="badge bg-danger text-white"><?php _s("AI Generated", __FILE__, $_SESSION["LANG"]); ?></span>
                                <span class="badge bg-light text-dark"><?php _s("Short Video", __FILE__, $_SESSION["LANG"]); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Web Search -->
        <div class="col-lg-6 col-md-12 mb-4">
            <div class="card border h-100">
                <div class="card-header bg-info text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-search"></i> <?php _s("Web Search", __FILE__, $_SESSION["LANG"]); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="flex-shrink-0 me-3">
                            <i class="fas fa-search fa-2x text-info"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="card-title">*/search [text]*</h6>
                            <p class="card-text"><?php _s("Searches the web for the specified text and returns a list of relevant links.", __FILE__, $_SESSION["LANG"]); ?></p>
                            <div class="mt-3">
                                <span class="badge bg-info text-white"><?php _s("Real-time", __FILE__, $_SESSION["LANG"]); ?></span>
                                <span class="badge bg-light text-dark"><?php _s("Web Results", __FILE__, $_SESSION["LANG"]); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Language Translation -->
        <div class="col-lg-6 col-md-12 mb-4">
            <div class="card border h-100">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-language"></i> <?php _s("Language Translation", __FILE__, $_SESSION["LANG"]); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="flex-shrink-0 me-3">
                            <i class="fas fa-language fa-2x text-warning"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="card-title">*/lang [en|de|fr|it|es|pt|nl] [text]*</h6>
                            <p class="card-text"><?php _s("Translates the following text to the selected language using 2-letter language codes.", __FILE__, $_SESSION["LANG"]); ?></p>
                            <div class="mt-3">
                                <span class="badge bg-warning text-dark"><?php _s("Multi-language", __FILE__, $_SESSION["LANG"]); ?></span>
                                <span class="badge bg-light text-dark"><?php _s("7 Languages", __FILE__, $_SESSION["LANG"]); ?></span>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <strong><?php _s("Supported languages:", __FILE__, $_SESSION["LANG"]); ?></strong> en, de, fr, it, es, pt, nl
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Web Page Reader -->
        <div class="col-lg-6 col-md-12 mb-4">
            <div class="card border h-100">
                <div class="card-header bg-secondary text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-globe"></i> <?php _s("Web Page Reader", __FILE__, $_SESSION["LANG"]); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="flex-shrink-0 me-3">
                            <i class="fas fa-globe fa-2x text-secondary"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="card-title">*/web [url]*</h6>
                            <p class="card-text"><?php _s("Reads the URL and creates a screenshot of the page. Currently in beta.", __FILE__, $_SESSION["LANG"]); ?></p>
                            <div class="mt-3">
                                <span class="badge bg-secondary text-white"><?php _s("Beta", __FILE__, $_SESSION["LANG"]); ?></span>
                                <span class="badge bg-light text-dark"><?php _s("Screenshot", __FILE__, $_SESSION["LANG"]); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Document Search -->
        <div class="col-lg-6 col-md-12 mb-4">
            <div class="card border h-100">
                <div class="card-header bg-dark text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-file-search"></i> <?php _s("Document Search", __FILE__, $_SESSION["LANG"]); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="flex-shrink-0 me-3">
                            <i class="fas fa-file-search fa-2x text-dark"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="card-title">*/docs [text]*</h6>
                            <p class="card-text"><?php _s("Searches your uploads (sound, video, images, docx and pdf files) for the specified text.", __FILE__, $_SESSION["LANG"]); ?></p>
                            <div class="mt-3">
                                <span class="badge bg-dark text-white"><?php _s("Local Search", __FILE__, $_SESSION["LANG"]); ?></span>
                                <span class="badge bg-light text-dark"><?php _s("Multiple Formats", __FILE__, $_SESSION["LANG"]); ?></span>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <strong><?php _s("Supported formats:", __FILE__, $_SESSION["LANG"]); ?></strong> PDF, DOCX, Images, Audio, Video
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Login Link -->
        <div class="col-lg-6 col-md-12 mb-4">
            <div class="card border h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-link"></i> <?php _s("Login Link", __FILE__, $_SESSION["LANG"]); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="flex-shrink-0 me-3">
                            <i class="fas fa-link fa-2x text-primary"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="card-title">*/link*</h6>
                            <p class="card-text"><?php _s("Generates a login link for your web profile to access your account from any device.", __FILE__, $_SESSION["LANG"]); ?></p>
                            <div class="mt-3">
                                <span class="badge bg-primary text-white"><?php _s("Secure", __FILE__, $_SESSION["LANG"]); ?></span>
                                <span class="badge bg-light text-dark"><?php _s("Profile Access", __FILE__, $_SESSION["LANG"]); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Usage Tips Section -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-lightbulb text-warning"></i> <?php _s("Usage Tips", __FILE__, $_SESSION["LANG"]); ?>
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6><i class="fas fa-check-circle text-success"></i> <?php _s("How to use:", __FILE__, $_SESSION["LANG"]); ?></h6>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-arrow-right text-primary"></i> <?php _s("Type the command exactly as shown", __FILE__, $_SESSION["LANG"]); ?></li>
                        <li><i class="fas fa-arrow-right text-primary"></i> <?php _s("Replace [text] with your actual content", __FILE__, $_SESSION["LANG"]); ?></li>
                        <li><i class="fas fa-arrow-right text-primary"></i> <?php _s("Use */list* to see all available commands", __FILE__, $_SESSION["LANG"]); ?></li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6><i class="fas fa-exclamation-triangle text-warning"></i> <?php _s("Important notes:", __FILE__, $_SESSION["LANG"]); ?></h6>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-info-circle text-info"></i> <?php _s("Some tools may take a few moments to process", __FILE__, $_SESSION["LANG"]); ?></li>
                        <li><i class="fas fa-info-circle text-info"></i> <?php _s("Beta features are still being improved", __FILE__, $_SESSION["LANG"]); ?></li>
                        <li><i class="fas fa-info-circle text-info"></i> <?php _s("File uploads are required for document search", __FILE__, $_SESSION["LANG"]); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="text-center mt-4 mb-4">
        <a href="index.php/chat" class="btn btn-primary me-2">
            <i class="fas fa-comments"></i> <?php _s("Start Chatting", __FILE__, $_SESSION["LANG"]); ?>
        </a>
        <a href="index.php/filemanager" class="btn btn-outline-secondary">
            <i class="fas fa-folder-open"></i> <?php _s("File Manager", __FILE__, $_SESSION["LANG"]); ?>
        </a>
    </div>
    <BR>
</main>