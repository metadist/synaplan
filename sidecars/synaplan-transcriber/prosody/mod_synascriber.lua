-- mod_synascriber: lets Synaplan (the synaScriber plugin) switch Jitsi's
-- bridge transcription on and off per room. Load it on the main VirtualHost
-- (docker-jitsi-meet: XMPP_MODULES=synascriber). The shared secret comes from
-- the environment variable SYNASCRIBER_SECRET (or option synascriber_secret).
--
-- HTTP API (JSON; every route except health needs "Authorization: Bearer <secret>"):
--   GET  /synascriber/health
--   GET  /synascriber/state?room=<name>          participants and transcription state
--   POST /synascriber/start {room, ref, language} sets asyncTranscription, urlParams, isTranscribingEnabled
--   POST /synascriber/stop  {room, ref}
--
-- Clients keep the right to stop transcription (moderators, as before) but can
-- no longer start it: only this module turns it on.

local jid = require 'util.jid';
local json = require 'cjson.safe';
local formdecode = require 'util.http'.formdecode;

local util = module:require 'util';
local get_room_from_jid = util.get_room_from_jid;
local is_healthcheck_room = util.is_healthcheck_room;
local is_jibri = util.is_jibri;
local is_transcriber = util.is_transcriber;
local process_host_module = util.process_host_module;

local VERSION = '0.3.0';
local muc_domain = module:get_option_string('synascriber_muc', 'muc.' .. module.host);
local secret = os.getenv('SYNASCRIBER_SECRET') or module:get_option_string('synascriber_secret', '');

if secret == '' then
    module:log('error', 'SYNASCRIBER_SECRET is not set: every API request will be refused');
end

local function response(status, body)
    return {
        status_code = status;
        headers = { ['Content-Type'] = 'application/json'; ['Cache-Control'] = 'no-store' };
        body = json.encode(body);
    };
end

local function authorized(event)
    if secret == '' then
        return false;
    end
    return (event.request.headers.authorization or '') == ('Bearer ' .. secret);
end

local function room_for(name)
    if type(name) ~= 'string' or name == '' or #name > 200 or name:find('[@/%s]') then
        return nil, 'invalid_room';
    end
    local room_jid = jid.prep(name:lower() .. '@' .. muc_domain);
    if not room_jid or is_healthcheck_room(room_jid) then
        return nil, 'invalid_room';
    end
    local room = get_room_from_jid(room_jid);
    if not room then
        return nil, 'room_not_found';
    end
    return room;
end

local function metadata(room)
    if not room.jitsiMetadata then
        room.jitsiMetadata = {};
    end
    return room.jitsiMetadata;
end

local function broadcast(room)
    module:context(muc_domain):fire_event('room-metadata-changed', { room = room; });
end

local function transcribing(room)
    local recording = room.jitsiMetadata and room.jitsiMetadata.recording;
    return (recording and recording.isTranscribingEnabled == true and room.jitsiMetadata.asyncTranscription == true) or false;
end

-- One participant: endpoint id, display name and, for signed-in people, the
-- identity from their Jitsi token (Keycloak sub and email).
local function describe(occupant)
    local endpoint = jid.resource(occupant.nick);
    local who = occupant.jid or '';
    if not endpoint or endpoint == 'focus' or who:match('^focus@') or is_jibri(occupant) or is_transcriber(who) then
        return nil;
    end
    local presence = occupant:get_presence();
    local entry = {
        id = endpoint;
        name = presence and presence:get_child_text('nick', 'http://jabber.org/protocol/nick') or '';
    };
    local session = prosody.full_sessions[who];
    local user = session and session.jitsi_meet_context_user;
    if type(user) == 'table' then
        entry.sub = type(user.id) == 'string' and user.id or nil;
        entry.email = type(user.email) == 'string' and user.email or nil;
        if entry.name == '' and type(user.name) == 'string' then
            entry.name = user.name;
        end
    end
    return entry;
end

local function participants(room)
    local list = {};
    for _, occupant in room:each_occupant() do
        local entry = describe(occupant);
        if entry then
            table.insert(list, entry);
        end
    end
    return list;
end

-- Everyone who was in the room while notes were on, also after they left.
local function remember(room, occupant)
    local entry = describe(occupant);
    if not entry or not room._synascriber_attendees then
        return;
    end
    room._synascriber_attendees[entry.sub or entry.email or entry.id] = entry;
end

local function attendees(room)
    local list = {};
    for _, entry in pairs(room._synascriber_attendees or {}) do
        table.insert(list, entry);
    end
    return list;
end

local function turn_on(room, ref, language)
    local meta = metadata(room);
    meta.asyncTranscription = true;
    meta.transcription = { urlParams = { notes = ref; lang = language; } };
    local recording = meta.recording or {};
    recording.isTranscribingEnabled = true;
    meta.recording = recording;
    room._synascriber_ref = ref;
    room._synascriber_attendees = {};
    for _, occupant in room:each_occupant() do
        remember(room, occupant);
    end
    broadcast(room);
end

local function turn_off(room)
    local meta = metadata(room);
    if meta.recording then
        meta.recording.isTranscribingEnabled = false;
    end
    meta.asyncTranscription = false;
    meta.transcription = nil;
    room._synascriber_ref = nil;
    broadcast(room);
end

local function body_of(event)
    local data = json.decode(event.request.body or '');
    if type(data) ~= 'table' then
        return nil;
    end
    return data;
end

local function handle_state(event)
    if not authorized(event) then
        return response(401, { ok = false; error = 'unauthorized' });
    end
    local params = formdecode(event.request.url.query or '') or {};
    local room, err = room_for(params.room);
    if not room then
        if err == 'room_not_found' then
            return response(200, { ok = true; exists = false; transcribing = false });
        end
        return response(400, { ok = false; error = err });
    end
    if room._synascriber_ref then
        for _, occupant in room:each_occupant() do
            remember(room, occupant);
        end
    end
    return response(200, {
        ok = true;
        exists = true;
        transcribing = transcribing(room);
        ref = room._synascriber_ref or json.null;
        meetingId = room._data and room._data.meetingId or json.null;
        participants = participants(room);
        attendees = attendees(room);
    });
end

local function handle_start(event)
    if not authorized(event) then
        return response(401, { ok = false; error = 'unauthorized' });
    end
    local data = body_of(event);
    if not data or type(data.ref) ~= 'string' or not data.ref:match('^[%w_%-]+$') then
        return response(400, { ok = false; error = 'invalid_request' });
    end
    local room, err = room_for(data.room);
    if not room then
        return response(err == 'room_not_found' and 404 or 400, { ok = false; error = err });
    end
    if room._synascriber_ref and room._synascriber_ref ~= data.ref and transcribing(room) then
        return response(409, { ok = false; error = 'already_running'; ref = room._synascriber_ref });
    end
    local language = type(data.language) == 'string' and data.language:match('^[%a%-]+$') and data.language or 'auto';
    turn_on(room, data.ref, language);
    module:log('info', 'synascriber: notes %s started in %s (%s)', data.ref, room.jid, language);
    return response(200, {
        ok = true;
        meetingId = room._data and room._data.meetingId or json.null;
        participants = participants(room);
        attendees = attendees(room);
    });
end

local function handle_stop(event)
    if not authorized(event) then
        return response(401, { ok = false; error = 'unauthorized' });
    end
    local data = body_of(event);
    if not data then
        return response(400, { ok = false; error = 'invalid_request' });
    end
    local room, err = room_for(data.room);
    if not room then
        if err == 'room_not_found' then
            return response(200, { ok = true; stopped = false; reason = 'room_ended' });
        end
        return response(400, { ok = false; error = err });
    end
    if data.ref and room._synascriber_ref and room._synascriber_ref ~= data.ref then
        return response(409, { ok = false; error = 'other_session'; ref = room._synascriber_ref });
    end
    local was_on = transcribing(room);
    turn_off(room);
    module:log('info', 'synascriber: notes %s stopped in %s', tostring(data.ref), room.jid);
    return response(200, { ok = true; stopped = was_on });
end

-- Clients may not switch transcription on; only this module does.
module:hook('jitsi-metadata-allow-moderation', function(event)
    if event.key ~= 'recording' or type(event.data) ~= 'table' or event.data.isTranscribingEnabled ~= true then
        return nil;
    end
    if transcribing(event.room) then
        return nil;
    end
    module:log('info', 'synascriber: refused a client start of transcription in %s', event.room.jid);
    return false;
end);

-- A moderator stopped transcription from Jitsi: clear our flags too.
-- People who join while notes are on are remembered as attendees.
process_host_module(muc_domain, function(host_module)
    host_module:hook('muc-occupant-joined', function(event)
        if event.room._synascriber_ref then
            remember(event.room, event.occupant);
        end
    end);

    host_module:hook('jitsi-metadata-updated', function(event)
        local room = event.room;
        if event.key ~= 'recording' or not room._synascriber_ref then
            return;
        end
        local recording = room.jitsiMetadata and room.jitsiMetadata.recording;
        if recording and recording.isTranscribingEnabled == false then
            module:log('info', 'synascriber: notes %s stopped by a participant in %s', room._synascriber_ref, room.jid);
            room.jitsiMetadata.asyncTranscription = false;
            room.jitsiMetadata.transcription = nil;
            room._synascriber_ref = nil;
            broadcast(room);
        end
    end);
end);

module:depends('http');
module:provides('http', {
    default_path = '/synascriber';
    route = {
        ['GET /health'] = function()
            return response(200, { ok = true; module = 'synascriber'; version = VERSION; muc = muc_domain });
        end;
        ['GET /state'] = handle_state;
        ['POST /start'] = handle_start;
        ['POST /stop'] = handle_stop;
    };
});

module:log('info', 'synascriber %s loaded (muc %s)', VERSION, muc_domain);
