// -----------------------------
function initSpeechButton(buttonId) {
    // Check if the button exists FIRST (it won't in widget mode)
    var startButton = document.getElementById(buttonId);
    if (!startButton) {
        console.log('Speech button not found, skipping speech initialization');
        return;
    }

    var resultDiv = document.getElementById('speechHints');

    // Check if speech recognition is available
    if (typeof webkitSpeechRecognition === 'undefined') {
        console.log('Speech recognition not available, skipping initialization');
        return;
    }

    // Create a new instance of webkitSpeechRecognition
    var recognition = new webkitSpeechRecognition();
    recognition.continuous = true; // Keep recognizing continuously
    recognition.interimResults = true; // Show interim results

    // Set the language (optional, default is 'en-US')
    recognition.lang = 'de-DE';

    // Handle the recognition event
    recognition.onresult = function (event) {
        let interimTranscript = '';
        let finalTranscript = '';

        for (let i = 0; i < event.results.length; i++) {
            if (event.results[i].isFinal) {
                finalTranscript += event.results[i][0].transcript;
                $('#messageInput').text(finalTranscript);
            } else {
                interimTranscript += event.results[i][0].transcript;
            }
        }
        // resultDiv.innerHTML = `<strong>Final:</strong> ${finalTranscript} <br><strong>Interim:</strong> ${interimTranscript}`;
        if (interimTranscript.length > 2) {
            $('#messageInput').text(interimTranscript);
        }
    };
    // Handle errors
    recognition.onerror = function (event) {
        console.error('Speech recognition error:', event.error);
    };

    // Start recognition
    startButton.addEventListener('mousedown', () => {
        recognition.start();
        $("#" + buttonId).addClass("activetalk").removeClass("inactivetalk");
        console.log('Speech recognition started');
    });

    // Start recognition
    startButton.addEventListener('touchstart', () => {
        recognition.start();
        $("#" + buttonId).addClass("activetalk").removeClass("inactivetalk");
        console.log('Speech recognition started');
    });

    // Stop recognition
    startButton.addEventListener('mouseup', () => {
        recognition.stop();
        $("#" + buttonId).addClass("inactivetalk").removeClass("activetalk");
        console.log('Speech recognition stopped');
        //sendUsrReply();
    });
    startButton.addEventListener('touchend', () => {
        recognition.stop();
        $("#" + buttonId).addClass("inactivetalk").removeClass("activetalk");
        console.log('Speech recognition stopped');
        //sendUsrReply();
    });
}
// -----------------------------
// -----------------------------
function speech(myText) {
    //console.log("Speak: " + myText);
    let utterance = new SpeechSynthesisUtterance(myText);
    window.speechSynthesis.speak(utterance);
}
// -----------------------------
$(document).ready(function() {
    // button inits - only for authenticated users
    if (typeof window.isAnonymousWidget === 'undefined' || !window.isAnonymousWidget) {
        // Check if the speech button actually exists before trying to initialize it
        const speakButton = document.getElementById("speakButton");
        if (speakButton) {
            initSpeechButton("speakButton");
        }
    }
});