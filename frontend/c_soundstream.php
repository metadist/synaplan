<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Sound2Text Streaming</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.href='index.php/chat'">
                    <i class="fas fa-comments"></i> Chat
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.href='index.php/tools'">
                    <i class="fas fa-tools"></i> Tools
                </button>
            </div>
        </div>
    </div>

    <form id="soundStreamForm" method="POST" action="index.php/soundstream">
        <input type="hidden" name="action" id="action" value="processAudio">
        
        <!-- Audio Configuration Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-cog"></i> Audio Configuration</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="audioSource" class="col-sm-2 col-form-label"><strong>Audio Source:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="audioSource" id="audioSource">
                            <option value="microphone">Microphone (Live)</option>
                            <option value="file">Audio File Upload</option>
                            <option value="url">Audio URL</option>
                        </select>
                        <div class="form-text">Source of audio input</div>
                    </div>
                    <label for="audioFormat" class="col-sm-2 col-form-label"><strong>Audio Format:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="audioFormat" id="audioFormat">
                            <option value="wav">WAV</option>
                            <option value="mp3" selected>MP3</option>
                            <option value="m4a">M4A</option>
                            <option value="ogg">OGG</option>
                            <option value="flac">FLAC</option>
                        </select>
                        <div class="form-text">Audio file format</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="sampleRate" class="col-sm-2 col-form-label"><strong>Sample Rate:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="sampleRate" id="sampleRate">
                            <option value="8000">8 kHz</option>
                            <option value="16000" selected>16 kHz</option>
                            <option value="22050">22.05 kHz</option>
                            <option value="44100">44.1 kHz</option>
                            <option value="48000">48 kHz</option>
                        </select>
                        <div class="form-text">Audio sampling rate</div>
                    </div>
                    <label for="channels" class="col-sm-2 col-form-label"><strong>Channels:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="channels" id="channels">
                            <option value="1" selected>Mono</option>
                            <option value="2">Stereo</option>
                        </select>
                        <div class="form-text">Audio channel configuration</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="language" class="col-sm-2 col-form-label"><strong>Language:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="language" id="language">
                            <option value="auto">Auto Detect</option>
                            <option value="en" selected>English</option>
                            <option value="de">Deutsch</option>
                            <option value="fr">Français</option>
                            <option value="es">Español</option>
                            <option value="it">Italiano</option>
                            <option value="pt">Português</option>
                            <option value="nl">Nederlands</option>
                            <option value="pl">Polski</option>
                            <option value="ru">Русский</option>
                            <option value="ja">日本語</option>
                            <option value="ko">한국어</option>
                            <option value="zh">中文</option>
                        </select>
                        <div class="form-text">Spoken language (auto-detect recommended)</div>
                    </div>
                    <label for="model" class="col-sm-2 col-form-label"><strong>Whisper Model:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="model" id="model">
                            <option value="tiny">Tiny (39 MB)</option>
                            <option value="base">Base (74 MB)</option>
                            <option value="small" selected>Small (244 MB)</option>
                            <option value="medium">Medium (769 MB)</option>
                            <option value="large">Large (1550 MB)</option>
                        </select>
                        <div class="form-text">Whisper model size (larger = better accuracy)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Audio Input Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-microphone"></i> Audio Input</h5>
            </div>
            <div class="card-body">
                <!-- Live Recording -->
                <div id="liveRecordingSection" class="input-section">
                    <div class="row mb-3">
                        <div class="col-12 text-center">
                            <div class="audio-visualizer mb-3">
                                <canvas id="audioCanvas" width="600" height="100" style="border: 1px solid #ddd; border-radius: 5px;"></canvas>
                            </div>
                            <div class="recording-controls">
                                <button type="button" class="btn btn-success btn-lg me-2" id="startRecording">
                                    <i class="fas fa-microphone"></i> Start Recording
                                </button>
                                <button type="button" class="btn btn-danger btn-lg me-2" id="stopRecording" disabled>
                                    <i class="fas fa-stop"></i> Stop Recording
                                </button>
                                <button type="button" class="btn btn-warning btn-lg" id="pauseRecording" disabled>
                                    <i class="fas fa-pause"></i> Pause
                                </button>
                            </div>
                            <div class="mt-3">
                                <span class="badge bg-info" id="recordingStatus">Ready to record</span>
                                <span class="badge bg-secondary ms-2" id="recordingTime">00:00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- File Upload -->
                <div id="fileUploadSection" class="input-section" style="display: none;">
                    <div class="row mb-3">
                        <label for="audioFile" class="col-sm-2 col-form-label"><strong>Upload Audio:</strong></label>
                        <div class="col-sm-10">
                            <input type="file" class="form-control" name="audioFile" id="audioFile" accept="audio/*">
                            <div class="form-text">Supported formats: WAV, MP3, M4A, OGG, FLAC (max 50MB)</div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-10 offset-sm-2">
                            <div class="audio-player" id="audioPlayer" style="display: none;">
                                <audio controls id="uploadedAudio" style="width: 100%;">
                                    Your browser does not support the audio element.
                                </audio>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- URL Input -->
                <div id="urlInputSection" class="input-section" style="display: none;">
                    <div class="row mb-3">
                        <label for="audioUrl" class="col-sm-2 col-form-label"><strong>Audio URL:</strong></label>
                        <div class="col-sm-10">
                            <input type="url" class="form-control" name="audioUrl" id="audioUrl" placeholder="https://example.com/audio.mp3">
                            <div class="form-text">Enter the URL of the audio file to transcribe</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Processing Options Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-sliders-h"></i> Processing Options</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="timestamps" class="col-sm-2 col-form-label"><strong>Include Timestamps:</strong></label>
                    <div class="col-sm-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="timestamps" id="timestamps" checked>
                            <label class="form-check-label" for="timestamps">
                                Add timestamps to transcription
                            </label>
                        </div>
                        <div class="form-text">Include time markers in output</div>
                    </div>
                    <label for="punctuation" class="col-sm-2 col-form-label"><strong>Add Punctuation:</strong></label>
                    <div class="col-sm-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="punctuation" id="punctuation" checked>
                            <label class="form-check-label" for="punctuation">
                                Add punctuation marks
                            </label>
                        </div>
                        <div class="form-text">Automatically add punctuation</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="speakerDetection" class="col-sm-2 col-form-label"><strong>Speaker Detection:</strong></label>
                    <div class="col-sm-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="speakerDetection" id="speakerDetection">
                            <label class="form-check-label" for="speakerDetection">
                                Detect multiple speakers
                            </label>
                        </div>
                        <div class="form-text">Identify different speakers in audio</div>
                    </div>
                    <label for="profanityFilter" class="col-sm-2 col-form-label"><strong>Profanity Filter:</strong></label>
                    <div class="col-sm-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="profanityFilter" id="profanityFilter">
                            <label class="form-check-label" for="profanityFilter">
                                Filter profanity
                            </label>
                        </div>
                        <div class="form-text">Censor inappropriate content</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="confidenceThreshold" class="col-sm-2 col-form-label"><strong>Confidence Threshold:</strong></label>
                    <div class="col-sm-4">
                        <input type="range" class="form-range" name="confidenceThreshold" id="confidenceThreshold" min="0" max="100" value="70">
                        <div class="form-text">Minimum confidence level: <span id="confidenceValue">70</span>%</div>
                    </div>
                    <label for="maxDuration" class="col-sm-2 col-form-label"><strong>Max Duration:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="maxDuration" id="maxDuration">
                            <option value="30">30 seconds</option>
                            <option value="60">1 minute</option>
                            <option value="300" selected>5 minutes</option>
                            <option value="600">10 minutes</option>
                            <option value="1800">30 minutes</option>
                        </select>
                        <div class="form-text">Maximum audio duration to process</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="card">
            <div class="card-body text-center">
                <div class="btn-group" role="group" aria-label="Audio processing actions">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-play"></i> Start Transcription
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg" onclick="clearForm()">
                        <i class="fas fa-eraser"></i> Clear Form
                    </button>
                    <button type="button" class="btn btn-info btn-lg" onclick="testAudio()">
                        <i class="fas fa-volume-up"></i> Test Audio
                    </button>
                </div>
                <div class="mt-3">
                    <small class="text-muted">Audio processing may take several minutes for longer files. Please be patient.</small>
                </div>
            </div>
        </div>

    </form>

    <!-- Results Section (Hidden by default) -->
    <div id="resultsSection" class="card mt-4" style="display: none;">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-file-text text-success"></i> Transcription Results
            </h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <h6>Transcribed Text:</h6>
                    <div id="transcriptionText" class="border p-3 bg-light" style="min-height: 200px; max-height: 400px; overflow-y: auto;">
                        <!-- Transcription content will be inserted here -->
                    </div>
                </div>
                <div class="col-md-6">
                    <h6>Processing Details:</h6>
                    <div id="processingDetails" class="border p-3 bg-light">
                        <!-- Processing details will be inserted here -->
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button type="button" class="btn btn-outline-primary" onclick="downloadTranscription()">
                    <i class="fas fa-download"></i> Download Transcription
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="copyTranscription()">
                    <i class="fas fa-copy"></i> Copy to Clipboard
                </button>
                <button type="button" class="btn btn-outline-info" onclick="sendToChat()">
                    <i class="fas fa-comments"></i> Send to Chat
                </button>
            </div>
        </div>
    </div>
</main>

<script>
    let mediaRecorder;
    let audioChunks = [];
    let recordingStartTime;
    let recordingTimer;
    let audioContext;
    let analyser;
    let microphone;
    let canvas;
    let canvasCtx;

    // Load page when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        initializeForm();
        initializeAudioVisualizer();
    });

    // Initialize form functionality
    function initializeForm() {
        // Handle audio source changes
        document.getElementById('audioSource').addEventListener('change', toggleInputSections);

        // Handle confidence threshold slider
        document.getElementById('confidenceThreshold').addEventListener('input', updateConfidenceValue);

        // Handle file upload
        document.getElementById('audioFile').addEventListener('change', handleFileUpload);

        // Initialize recording controls
        initializeRecordingControls();
    }

    // Initialize audio visualizer
    function initializeAudioVisualizer() {
        canvas = document.getElementById('audioCanvas');
        canvasCtx = canvas.getContext('2d');
    }

    // Toggle input sections based on selected source
    function toggleInputSections() {
        const sections = document.querySelectorAll('.input-section');
        sections.forEach(section => section.style.display = 'none');
        
        const selectedSource = document.getElementById('audioSource').value;
        document.getElementById(selectedSource + 'Section').style.display = 'block';
    }

    // Update confidence value display
    function updateConfidenceValue() {
        const value = document.getElementById('confidenceThreshold').value;
        document.getElementById('confidenceValue').textContent = value;
    }

    // Handle file upload
    function handleFileUpload(event) {
        const file = event.target.files[0];
        if (file) {
            const audioPlayer = document.getElementById('audioPlayer');
            const audio = document.getElementById('uploadedAudio');
            audio.src = URL.createObjectURL(file);
            audioPlayer.style.display = 'block';
        }
    }

    // Initialize recording controls
    function initializeRecordingControls() {
        document.getElementById('startRecording').addEventListener('click', startRecording);
        document.getElementById('stopRecording').addEventListener('click', stopRecording);
        document.getElementById('pauseRecording').addEventListener('click', pauseRecording);
    }

    // Start recording
    async function startRecording() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(stream);
            audioChunks = [];

            mediaRecorder.ondataavailable = (event) => {
                audioChunks.push(event.data);
            };

            mediaRecorder.onstop = () => {
                const audioBlob = new Blob(audioChunks, { type: 'audio/wav' });
                // TODO: Process the recorded audio
                console.log('Recording stopped, processing audio...');
            };

            mediaRecorder.start();
            recordingStartTime = Date.now();
            updateRecordingTime();
            recordingTimer = setInterval(updateRecordingTime, 1000);

            document.getElementById('startRecording').disabled = true;
            document.getElementById('stopRecording').disabled = false;
            document.getElementById('pauseRecording').disabled = false;
            document.getElementById('recordingStatus').textContent = 'Recording...';
            document.getElementById('recordingStatus').className = 'badge bg-danger';

        } catch (error) {
            console.error('Error accessing microphone:', error);
            alert('Error accessing microphone. Please check permissions.');
        }
    }

    // Stop recording
    function stopRecording() {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
            clearInterval(recordingTimer);
            
            document.getElementById('startRecording').disabled = false;
            document.getElementById('stopRecording').disabled = true;
            document.getElementById('pauseRecording').disabled = true;
            document.getElementById('recordingStatus').textContent = 'Processing...';
            document.getElementById('recordingStatus').className = 'badge bg-warning';
        }
    }

    // Pause recording
    function pauseRecording() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.pause();
            document.getElementById('pauseRecording').innerHTML = '<i class="fas fa-play"></i> Resume';
        } else if (mediaRecorder && mediaRecorder.state === 'paused') {
            mediaRecorder.resume();
            document.getElementById('pauseRecording').innerHTML = '<i class="fas fa-pause"></i> Pause';
        }
    }

    // Update recording time display
    function updateRecordingTime() {
        if (recordingStartTime) {
            const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
            const minutes = Math.floor(elapsed / 60);
            const seconds = elapsed % 60;
            document.getElementById('recordingTime').textContent = 
                `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
    }

    // Clear form
    function clearForm() {
        document.getElementById('soundStreamForm').reset();
        document.getElementById('audioFile').value = '';
        document.getElementById('audioPlayer').style.display = 'none';
        document.getElementById('resultsSection').style.display = 'none';
        
        // Reset recording state
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }
        clearInterval(recordingTimer);
        document.getElementById('startRecording').disabled = false;
        document.getElementById('stopRecording').disabled = true;
        document.getElementById('pauseRecording').disabled = true;
        document.getElementById('recordingStatus').textContent = 'Ready to record';
        document.getElementById('recordingStatus').className = 'badge bg-info';
        document.getElementById('recordingTime').textContent = '00:00';
    }

    // Test audio
    function testAudio() {
        // TODO: Implement audio testing
        console.log('Testing audio...');
    }

    // Download transcription
    function downloadTranscription() {
        // TODO: Implement download functionality
        console.log('Downloading transcription...');
    }

    // Copy transcription to clipboard
    function copyTranscription() {
        // TODO: Implement copy functionality
        console.log('Copying transcription to clipboard...');
    }

    // Send to chat
    function sendToChat() {
        // TODO: Implement send to chat functionality
        console.log('Sending transcription to chat...');
    }
</script>

<style>
    .card-header h5 {
        color: #495057;
    }
    .btn-group .btn {
        min-width: 150px;
    }
    .text-muted {
        font-size: 0.875rem;
    }
    .form-range {
        height: 6px;
    }
    .input-section {
        transition: all 0.3s ease;
    }
    .audio-visualizer {
        background: #f8f9fa;
        border-radius: 5px;
        padding: 10px;
    }
    .recording-controls {
        margin: 20px 0;
    }
    .badge {
        font-size: 0.9rem;
        padding: 8px 12px;
    }
</style> 