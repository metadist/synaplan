<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <H1>Configuration of your setup</H1>
    <p>Define your own prompts and input handling!</p>
    
    <!-- Image map container -->
    <div class="image-map-container" style="position: relative; max-height: 70vh;">
        <img src="assets/statics/img/architecture_1.png" 
             alt="Configuration of your setup" 
             class="img-fluid responsive-image" 
             style="max-height: 70vh; height: auto;"
             usemap="#architecture-map"
             id="architectureImage">
        
        <map name="architecture-map" id="architectureMap">
            <!-- Area 1: Top-left section -->
            <area shape="rect" coords="120,10,940,330" href="#" alt="Inbound Options" data-area="1" class="map-area">
            <!-- Area 2: Top-right section -->
            <area shape="rect" coords="120,360,940,520" href="#" alt="Sorting Prompt(s)" data-area="2" class="map-area">
            <!-- Area 3: Bottom-left section -->
            <area shape="rect" coords="50,550,940,850" href="#" alt="Your Prompts" data-area="3" class="map-area">
            <!-- Area 4: Bottom-right section -->
            <area shape="rect" coords="960,50,1450,890" href="#" alt="AI Services" data-area="4" class="map-area">
        </map>
        
        <!-- Overlay for visual feedback -->
        <div id="areaOverlay" class="area-overlay" style="
            position: absolute;
            border: 2px solid #007bff;
            background-color: rgba(0, 123, 255, 0.1);
            display: none;
            pointer-events: none;
            z-index: 10;
        "></div>
    </div>

    <script>
    class ResponsiveImageMap {
        constructor(imageId, mapId, overlayId) {
            this.image = document.getElementById(imageId);
            this.map = document.getElementById(mapId);
            this.overlay = document.getElementById(overlayId);
            this.areas = this.map.querySelectorAll('area');
            this.originalCoords = [];
            this.isTouch = false;
            
            this.init();
        }
        
        init() {
            // Store original coordinates
            this.areas.forEach((area, index) => {
                this.originalCoords[index] = area.coords.split(',').map(Number);
            });
            
            // Wait for image to load
            if (this.image.complete) {
                this.setupEvents();
                this.resizeMap();
            } else {
                this.image.onload = () => {
                    this.setupEvents();
                    this.resizeMap();
                };
            }
            
            // Handle window resize
            window.addEventListener('resize', () => this.resizeMap());
        }
        
        setupEvents() {
            this.areas.forEach((area) => {
                // Mouse events
                area.addEventListener('mouseenter', (e) => {
                    if (!this.isTouch) {
                        this.showOverlay(e.target);
                        this.onAreaEnter(e.target.dataset.area);
                    }
                });
                
                area.addEventListener('mouseleave', (e) => {
                    if (!this.isTouch) {
                        this.hideOverlay();
                        this.onAreaLeave(e.target.dataset.area);
                    }
                });
                
                area.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.onAreaClick(e.target.dataset.area);
                });
                
                // Touch events
                area.addEventListener('touchstart', (e) => {
                    this.isTouch = true;
                    this.showOverlay(e.target);
                    this.onAreaEnter(e.target.dataset.area);
                }, { passive: true });
                
                area.addEventListener('touchend', (e) => {
                    e.preventDefault();
                    setTimeout(() => {
                        this.hideOverlay();
                        this.onAreaLeave(e.target.dataset.area);
                        this.onAreaClick(e.target.dataset.area);
                    }, 150);
                });
            });
            
            // Reset touch flag after a delay
            document.addEventListener('touchend', () => {
                setTimeout(() => { this.isTouch = false; }, 300);
            });
        }
        
        resizeMap() {
            if (!this.image.naturalWidth || !this.image.naturalHeight) return;
            
            const rect = this.image.getBoundingClientRect();
            const scaleX = rect.width / this.image.naturalWidth;
            const scaleY = rect.height / this.image.naturalHeight;
            
            this.areas.forEach((area, index) => {
                const originalCoords = this.originalCoords[index];
                const scaledCoords = originalCoords.map((coord, i) => {
                    return Math.round(coord * (i % 2 === 0 ? scaleX : scaleY));
                });
                area.coords = scaledCoords.join(',');
            });
        }
        
        showOverlay(area) {
            const coords = area.coords.split(',').map(Number);
            const rect = this.image.getBoundingClientRect();
            const imageRect = this.image.parentElement.getBoundingClientRect();

            this.overlay.style.left = (coords[0] + rect.left - imageRect.left) + 'px';
            this.overlay.style.top = (coords[1] + rect.top - imageRect.top) + 'px';
            this.overlay.style.width = (coords[2] - coords[0]) + 'px';
            this.overlay.style.height = (coords[3] - coords[1]) + 'px';
            this.overlay.style.display = 'block';
        }
        
        hideOverlay() {
            $("#menuPoint1").removeClass("menuPoint");
            $("#menuPoint2").removeClass("menuPoint");
            $("#menuPoint3").removeClass("menuPoint");
            $("#menuPoint4").removeClass("menuPoint");
            this.overlay.style.display = 'none';
        }
        
        onAreaEnter(areaNumber) {
            console.log(`Entered area ${areaNumber}`);
            // Add your custom logic here
            $("#menuPoint" + areaNumber.toString()).addClass("menuPoint");
            this.showAreaInfo(areaNumber);
        }
        
        onAreaLeave(areaNumber) {
            console.log(`Left area ${areaNumber}`);
            // Add your custom logic here
            this.hideAreaInfo();
        }
        
        onAreaClick(areaNumber) {
            console.log(`Clicked area ${areaNumber}`);
            // Add your custom logic here
            
            // Navigate to corresponding menu destinations
            const destinations = {
                1: "index.php/inbound",        // menuPoint1 - Inbound
                2: "index.php/preprocessor",   // menuPoint2 - Sorting Prompt
                3: "index.php/prompts",        // menuPoint3 - Task Prompts
                4: "index.php/aimodels"        // menuPoint4 - AI Models
            };
            
            if (destinations[areaNumber]) {
                window.location.href = destinations[areaNumber];
            }
        }
        
        showAreaInfo(areaNumber) {
            // You can customize this to show specific information for each area
            const info = {
                1: "Inbound Options: WhatsApp, Mail, etc.",
                2: "Dealing with the incoming messages",
                3: "Working with your prompts and your data",
                4: "External LLMs and other AI services"
            };
            
            // Example: Update a status element or show tooltip
            document.title = `Hovering: ${info[areaNumber] || 'Area ' + areaNumber}`;
        }
        
        hideAreaInfo() {
            document.title = "Configuration of your setup";
        }
    }
    
    // Initialize the responsive image map when the page loads
    document.addEventListener('DOMContentLoaded', function() {
        const imageMap = new ResponsiveImageMap('architectureImage', 'architectureMap', 'areaOverlay');
        
        // Make it globally accessible if needed
        window.architectureMap = imageMap;
    });
    </script>
</main>