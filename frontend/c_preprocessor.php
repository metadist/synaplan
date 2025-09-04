<?php
    //require_once('inc/_preprocessconf.php');
?>
<script src="node_modules/markdown-it/dist/markdown-it.min.js"></script>
<script>
    var markDownIt = window.markdownit({
            html: true,
            linkify: true,
            typographer: true
        });
</script>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <H1>Preprocessor</H1>
    <p>
        The preprocessor handles the incoming message. It gets the message as a JSON object and returns a JSON object.
        <BR>
        See the <a href="index.php/prompts" style="font-weight: bold;">Prompt Editor</a> to change existing prompts OR add new prompts.
    </p>
    <?php
        $promptText = BasicAI::getAprompt("tools:sort")["BPROMPT"];
    ?>
    <HR>
    <ul class="nav nav-tabs" id="mdTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="resultTab" data-bs-toggle="tab" data-bs-target="#result" type="button" 
            role="tab" aria-controls="result" aria-selected="false">Rendered Result</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="editorTab" data-bs-toggle="tab" data-bs-target="#editor" type="button" 
            role="tab" aria-controls="editor" aria-selected="true">Prompt Source</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="jsonTab" data-bs-toggle="tab" data-bs-target="#json" type="button" 
            role="tab" aria-controls="json" aria-selected="true">JSON Object</button>
        </li>
    </ul>
    <div class="tab-content py-2" id="myTabContent">
        <div class="tab-pane fade show active" id="result" role="tabpanel" aria-labelledby="resultTab">
            <div id="mdRendered" class="p-2 border rounded" style="background-color: #EFEFEF;"></div>
        </div>
        <div class="tab-pane fade" id="editor" role="tabpanel" aria-labelledby="editorTab">
            <textarea id="mdInput" class="form-control" 
            style="min-height: 500px; width: 100%; font-family: monospace; font-weight: bolder; background-color: #EFEFEF;"><?php 
            echo $promptText; ?></textarea>
        </div>
        <div class="tab-pane fade" id="json" role="tabpanel" aria-labelledby="jsonTab">
            <div id="jsonOutput" class="p-2 border rounded" style="background-color: #EFEFEF;">
                The JSON object is the form we save your request into the database.<BR>
                The whole object is passed to the preprocessor.
                The preprocessor can change the object and return it to the central process.
                The central process will save the changed object into the database.
                <BR>
                Here is an example of the JSON object:<BR><BR>
<pre>
{
    "BDATETIME": "20250314182858",
    "BFILEPATH": "123/4321/soundfile.mp3",
    "BTOPIC": "",
    "BLANG": "en",
    "BTEXT": "Please help me to translate this message to Spanish.",
    "BFILETEXT": "Hello, this text was extracted from the sound file."
}
</pre>
                The preprocessor can also return a new JSON object. 
                That is setting the topic and by that, directing the message to a specific prompt.
                The <B>BTOPIC</B> is the name of YOUR TARGET prompt!
            </div>
        </div>
    </div>
</main>
<BR><BR>
&nbsp;
<br>
<script>
    var mdText = markDownIt.render($("#mdInput").val());
    $("#mdRendered").html(mdText);

    var tabEl = document.getElementById("resultTab");

    tabEl.addEventListener('show.bs.tab', function (event) {
        mdText = markDownIt.render($("#mdInput").val());
        $("#mdRendered").html(mdText);
    });
</script>