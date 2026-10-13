const output = document.getElementById('output');
const input = document.getElementById('commandInput');
const promptEl = document.getElementById('prompt');
const suggestionBar = document.getElementById('suggestionBar');
const stateNode = document.getElementById('terminal-state');

const rawState = stateNode?.content?.textContent || stateNode?.textContent || '{}';
const serverState = JSON.parse(rawState);

const state = {
    mode: 'idle',
    suggestion: '',
    shellLocation: 'root',
    activeChannel: serverState.activeChannel || serverState.channels?.[0]?.id || '',
    appUrl: serverState.appUrl || '',
    csrfToken: serverState.csrfToken || '',
    user: serverState.user || {},
    deviceTrustRequired: Boolean(serverState.deviceTrustRequired),
    trustedDevice: serverState.trustedDevice || null,
    availableUsers: serverState.availableUsers || [],
    deviceUsers: serverState.deviceUsers || [],
    unopenedCount: serverState.unopenedCount || 0,
    channels: serverState.channels || [],
    drops: serverState.drops || [],
    channelCreate: {
        name: '',
        defaultExpiryMinutes: '',
        maxExpiryMinutes: ''
    },
    memberAdd: {
        username: ''
    },
    memberRemove: {
        memberRef: ''
    },
    userCreate: {
        username: '',
        password: ''
    },
    deviceCreate: {
        label: '',
        userRef: '',
        expiresHours: ''
    },
    compose: {
        recipientRef: '',
        recipientName: '',
        expiryMinutes: '',
        body: '',
        contact: null,
        assessment: null
    },
    verify: {
        contact: null,
        assessment: null
    }
};

function focusInput() {
    input.focus();
}

function print(text = '', className = '') {
    const line = document.createElement('div');
    line.className = `line ${className}`.trim();
    line.textContent = text;
    output.appendChild(line);
    output.scrollTop = output.scrollHeight;
}

function printHtml(html) {
    const wrap = document.createElement('div');
    wrap.className = 'line line-html';
    wrap.innerHTML = html;
    output.appendChild(wrap);
    output.scrollTop = output.scrollHeight;
}

function route(path) {
    return String(path || '').replace(/^\/+/, '');
}

function setSuggestion(command, reason = '') {
    state.suggestion = command || '';

    const commands = contextCommands();

    if (!command && !commands.length) {
        suggestionBar.textContent = '';
        return;
    }

    const suggestion = command
        ? `<span class="suggestion-primary">suggestion: <strong>${escapeHtml(command)}</strong>${reason ? ` <span class="muted">// ${escapeHtml(reason)}</span>` : ''} <span class="dim">[tab]</span></span>`
        : '';

    const commandList = commands.length
        ? `<span class="context-commands">${commands.map(item => `<button type="button" data-command="${escapeHtml(item.command)}">${escapeHtml(item.label)}</button>`).join('')}</span>`
        : '';

    suggestionBar.innerHTML = `${suggestion}${commandList}`;
}

function contextCommands() {
    if (state.mode === 'compose_recipient') {
        return [
            { label: 'choose recipient', command: state.suggestion || '1' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'compose_expiry') {
        return [
            { label: '15m', command: '15' },
            { label: '1h', command: '60' },
            { label: '24h', command: '1440' },
            { label: '7d', command: '10080' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'compose_body') {
        return [
            { label: 'type message', command: '' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'compose_keychange') {
        return [
            { label: 'accept (na controle)', command: 'accept' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'compose_sign') {
        return [
            { label: 'type private pass', command: '' },
            { label: 'anon', command: 'anon' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'verify_confirm') {
        return [
            { label: 'yes', command: 'yes' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'member_username') {
        const users = availableUsersForActiveChannel().slice(0, 6).map((user, index) => ({
            label: `[${index + 1}] ${user.username}`,
            command: String(index + 1)
        }));

        users.push({ label: 'cancel', command: 'cancel' });

        return users.length > 1 ? users : [
            { label: 'no users available', command: '' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'member_remove') {
        const members = removableMembersForActiveChannel().slice(0, 6).map((member, index) => ({
            label: `[${index + 1}] ${member.username}`,
            command: String(index + 1)
        }));

        members.push({ label: 'cancel', command: 'cancel' });

        return members.length > 1 ? members : [
            { label: 'no removable members', command: '' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'channel_name') {
        return [
            { label: 'type channel name', command: '' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'user_create_username') {
        return [
            { label: 'type username', command: '' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'user_create_password') {
        return [
            { label: 'type temp password', command: '' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'device_create_label') {
        return [
            { label: 'type label', command: '' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'device_create_user') {
        const users = state.deviceUsers.slice(0, 5).map((user, index) => ({
            label: `[${index + 1}] ${user.username}`,
            command: String(index + 1)
        }));

        return [
            { label: 'any user', command: 'any' },
            ...users,
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'device_create_expiry') {
        return [
            { label: '1h', command: '1' },
            { label: '8h', command: '8' },
            { label: '24h', command: '24' },
            { label: '72h', command: '72' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'channel_default_expiry' || state.mode === 'channel_max_expiry') {
        return [
            { label: '15m', command: '15' },
            { label: '1h', command: '60' },
            { label: '24h', command: '1440' },
            { label: '7d', command: '10080' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'channel_update_name') {
        return [
            { label: 'type channel name', command: '' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.mode === 'channel_update_default_expiry' || state.mode === 'channel_update_max_expiry') {
        return [
            { label: '15m', command: '15' },
            { label: '1h', command: '60' },
            { label: '24h', command: '1440' },
            { label: '7d', command: '10080' },
            { label: 'cancel', command: 'cancel' }
        ];
    }

    if (state.shellLocation === 'channel') {
        const commands = [
            { label: 'inbox', command: 'inbox' },
            { label: 'members', command: 'members' },
            { label: 'compose', command: 'compose' }
        ];

        if (canManageActiveChannel()) {
            commands.push({ label: 'add member', command: 'add member' });
            commands.push({ label: 'remove member', command: 'remove member' });
            commands.push({ label: 'settings', command: 'settings' });
        }

        commands.push(
            { label: 'back', command: 'back' },
            { label: 'channels', command: 'channels' },
            { label: 'help', command: 'help' },
            { label: 'ui', command: 'ui' }
        );

        return commands;
    }

    const rootCommands = [
        { label: 'channels', command: 'channels' },
        { label: 'inbox', command: 'inbox' },
        { label: 'status', command: 'status' },
        { label: 'help', command: 'help' },
        { label: 'ui', command: 'ui' }
    ];

    if (isAdmin()) {
        rootCommands.push(
            { label: 'create channel', command: 'create channel' },
            { label: 'create user', command: 'create user' },
            { label: 'create device', command: 'create device' },
            { label: 'admin', command: 'admin' },
            { label: 'users', command: 'users' },
            { label: 'devices', command: 'devices' }
        );
    }

    if (isSuperadmin()) {
        rootCommands.push({ label: 'node', command: 'node' });
        rootCommands.push({ label: 'identity', command: 'identity' });
    }

    return rootCommands;
}

function activeChannel() {
    return state.channels.find(channel => channel.id === state.activeChannel) || null;
}

function promptText() {
    const channel = activeChannel();

    if (state.shellLocation === 'channel' && channel) {
        return `node@deaddrop:/${slug(channel.label)}$`;
    }

    return 'node@deaddrop:~$';
}

function updatePrompt() {
    promptEl.textContent = promptText();
}

function slug(value) {
    return String(value || 'channel')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 24) || 'channel';
}

function channelDrops(channelId = state.activeChannel) {
    return state.drops.filter(drop => drop.channel === channelId);
}

function allDrops() {
    return state.drops;
}

function channelRecipients(channel = activeChannel()) {
    return (channel?.memberList || []).filter(member => {
        return member.publicId !== state.user.publicId && member.hasKey;
    });
}

function availableUsersForActiveChannel(channel = activeChannel()) {
    const memberPublicIds = new Set((channel?.memberList || []).map(member => member.publicId));

    return state.availableUsers.filter(user => !memberPublicIds.has(user.publicId));
}

function removableMembersForActiveChannel(channel = activeChannel()) {
    return (channel?.memberList || []).filter(member => {
        return member.publicId !== state.user.publicId && member.role !== 'owner';
    });
}

function resolveAvailableUser(value, users = availableUsersForActiveChannel()) {
    const query = value.trim().toLowerCase();
    const userNumber = Number(query);

    if (Number.isInteger(userNumber) && userNumber > 0) {
        return users[userNumber - 1] || null;
    }

    return users.find(user => {
        return user.username.toLowerCase() === query || user.publicId.toLowerCase() === query;
    }) || null;
}

function resolveRemovableMember(value, members = removableMembersForActiveChannel()) {
    const query = value.trim().toLowerCase();
    const memberNumber = Number(query);

    if (Number.isInteger(memberNumber) && memberNumber > 0) {
        return members[memberNumber - 1] || null;
    }

    return members.find(member => {
        return member.username.toLowerCase() === query || member.publicId.toLowerCase() === query;
    }) || null;
}

function boot() {
    print('DEADDROP TERMINAL v0.4', 'boot');
    print('session: active');
    print(`identity: ${state.user.username || 'unknown'}`);
    print(`public id: ${state.user.publicId || 'unknown'}`);
    print(`role: ${state.user.role || 'unknown'}`);
    print(`waiting drops: ${state.unopenedCount}`);

    const channel = activeChannel();
    if (channel) {
        print(`selected channel: ${channel.label}`);
    } else {
        print('selected channel: none', 'warning');
    }

    if (serverState.notice) {
        print(serverState.notice, 'green');
    }

    if (serverState.error) {
        print(serverState.error, 'danger');
    }

    print('sender visibility: server none / recipient signed');
    print('message model: recipient public key / signed envelope / burn after read');
    print('');
    print('Typ help voor commands.', 'muted');
    print('Gebruik TAB om de suggestie over te nemen.', 'muted');
    print('');
    setSuggestion(state.channels.length ? 'inbox' : 'status', state.channels.length ? 'check waiting drops' : 'no channels yet');
}

function help() {
    print('DEADDROP terminal help', 'green');
    print('');
    print('Navigation', 'green');
    print('  channels              toon jouw channels als selecteerbare kaarten');
    print('  use <nummer|naam>     ga een channel-map in');
    print('  back / cd ..          terug naar root');
    print('  channel               open huidig channel in simpele view');
    print('  ui                    terug naar dashboard');
    print('');
    print('Messaging', 'green');
    print('  inbox                 root: overzicht per channel, channel: berichten');
    print('  open <drop_id>        ga naar decrypt-scherm');
    print('  compose               maak ondertekende E2EE drop voor member met public key');
    print('');
    print('Keys', 'green');
    print('  fingerprint           toon jouw fingerprint');
    print('  fingerprint <user>    toon fingerprint van een member in dit channel');
    print('  verify <user>         markeer fingerprint als buiten Deaddrop gecontroleerd');
    print('  keys                  open sleutelbeheer (pass wijzigen, back-up, roteren)');
    print('');
    print('Members', 'green');
    print('  members               toon leden + key-status in huidig channel');
    print('  add member            guided picker met beschikbare users');
    print('  add member <nummer>   quick add uit de picker-lijst');
    print('  add member <username> quick add op username');
    print('  remove member         guided picker om member te verwijderen');
    print('  settings              toon channel instellingen');
    print('  set channel           update naam/default/max expiry');
    print('                         alleen owner/admin in dit channel');

    if (isAdmin()) {
        print('');
        print('Admin', 'green');
        print('  create channel        maak nieuw channel aan');
        print('  create user           maak normale user aan');
        print('  create device         maak activatiecode voor trusted device');
        print('  admin                 open admin overzicht');
        print('  users                 open user management');
        print('  devices               open device management');
    }
    if (isSuperadmin()) {
        print('  node                  open read-only node diagnostics');
        print('  identity              open identity provisioning overview');
    }
    print('');
    print('Session', 'green');
    print('  whoami                toon huidige sessie');
    print('  status                technische/security status');
    print('  logout                sessie afsluiten');
    print('  clear                 scherm leegmaken');
    print('');
    print('Flow: channels -> use <nummer> -> members -> add member -> compose -> inbox', 'muted');
    setSuggestion(state.channels.length ? 'inbox' : 'channels', 'most common next step');
}

function channels() {
    if (!state.channels.length) {
        print('no authorized channels found', 'warning');
        setSuggestion('status', 'show session status');
        return;
    }

    print('authorized channels:', 'green');

    const cards = state.channels.map((channel, index) => {
        const waiting = channelDrops(channel.id).length;
        const number = index + 1;

        return `
            <button type="button" class="terminal-card terminal-card-compact" data-command="use ${number}">
                <strong>[${number}] ${escapeHtml(channel.label)}</strong>
                <span>role: ${escapeHtml(channel.role)}</span>
                <span>members: ${channel.members}</span>
                <span>drops: ${waiting}</span>
                <code>use ${number}</code>
            </button>
        `;
    }).join('');

    printHtml(`<div class="terminal-grid">${cards}</div>`);

    setSuggestion('', '');
}

function useChannel(value) {
    const query = value.trim().toLowerCase();
    const channelNumber = Number(query);
    const channel = Number.isInteger(channelNumber) && channelNumber > 0
        ? state.channels[channelNumber - 1]
        : state.channels.find(item => {
        return item.id.toLowerCase() === query || item.label.toLowerCase() === query;
    });

    if (!channel) {
        print(`channel not found or not authorized: ${value}`, 'danger');
        setSuggestion('channels', 'show authorized channels');
        return;
    }

    state.activeChannel = channel.id;
    state.shellLocation = 'channel';
    updatePrompt();
    print(`entered channel: ${channel.label}`, 'green');
    print(`path: /${slug(channel.label)}`, 'muted');
    setSuggestion('members', 'inspect channel members');
}

function backToRoot() {
    state.shellLocation = 'root';
    updatePrompt();
    print('path: ~', 'green');
    setSuggestion('channels', 'show channels');
}

function members() {
    const channel = activeChannel();

    if (!channel) {
        print('no active channel', 'warning');
        setSuggestion('channels', 'select a channel');
        return;
    }

    const memberList = channel.memberList || [];

    print(`channel: ${channel.label}`, 'green');
    print(`${memberList.length} member(s)`);

    if (!memberList.length) {
        print('no members found', 'warning');
        return;
    }

    memberList.forEach(member => {
        const keyStatus = member.hasKey ? 'key: active' : 'key: missing';
        print(`  ${member.username} (${member.role}, ${keyStatus})`);
    });

    if (canManageActiveChannel()) {
        setSuggestion('add member', 'add user to this channel');
    } else {
        setSuggestion('inbox', 'return to drops');
    }
}

function channelSettings() {
    const channel = activeChannel();

    if (!channel) {
        print('no active channel', 'warning');
        setSuggestion('channels', 'select a channel');
        return;
    }

    print('channel settings', 'green');
    print(`  name: ${channel.label}`);
    print(`  role: ${channel.role}`);
    print(`  members: ${channel.members}`);
    print(`  default expiry: ${channel.defaultExpiryMinutes}m`);
    print(`  max expiry: ${channel.maxExpiryMinutes}m`);

    if (canManageActiveChannel()) {
        setSuggestion('set channel', 'update channel settings');
    } else {
        setSuggestion('members', 'show channel members');
    }
}

function inbox() {
    const channel = activeChannel();

    if (state.shellLocation !== 'channel') {
        inboxOverview();
        return;
    }

    if (!channel) {
        print('no active channel', 'warning');
        setSuggestion('channels', 'select a channel');
        return;
    }

    inboxForChannel(channel);
}

function inboxOverview() {
    const drops = allDrops();

    if (!drops.length) {
        print('global inbox empty', 'muted');
        setSuggestion('channels', 'show channels');
        return;
    }

    print(`global inbox: ${drops.length} available drop(s)`, 'green');

    const cards = state.channels
        .map((channel, index) => {
            const channelDropCount = channelDrops(channel.id).length;

            if (!channelDropCount) {
                return '';
            }

            return `
                <button type="button" class="terminal-card terminal-card-compact" data-command="use ${index + 1}">
                    <strong>${escapeHtml(channel.label)}</strong>
                    <span>waiting drops: ${channelDropCount}</span>
                    <span>members: ${channel.members}</span>
                    <code>use ${index + 1}</code>
                </button>
            `;
        })
        .join('');

    printHtml(`<div class="terminal-grid">${cards}</div>`);
    print('Gebruik use <nummer> om naar het channel te gaan en daarna inbox voor details.', 'muted');
    setSuggestion('', '');
}

function inboxForChannel(channel) {
    const drops = channelDrops();

    if (!drops.length) {
        print(`inbox empty in ${channel.label}`, 'muted');
        setSuggestion('compose', 'create a new drop');
        return;
    }

    print(`${channel.label}: ${drops.length} available drop(s)`, 'green');
    drops.forEach(drop => {
        printHtml(`
            <div class="terminal-card">
                <strong>${escapeHtml(drop.id)}</strong>\nchannel: ${escapeHtml(drop.channelLabel)}\ncreated: ${escapeHtml(drop.created)}\nexpires: ${escapeHtml(drop.expires)}\nstatus: ${escapeHtml(drop.status)}\nfailed attempts: ${drop.failedAttempts}\ncommand: open ${escapeHtml(drop.id)}
            </div>
        `);
    });

    setSuggestion(`open ${drops[0].id}`, 'open next sealed drop');
}

function openDrop(id) {
    const drop = state.drops.find(item => item.id === id);

    if (!drop) {
        print(`drop not found: ${id}`, 'danger');
        setSuggestion('inbox', 'view available drops');
        return;
    }

    print(`opening ${id}`, 'green');
    window.location.href = route(`message_open.php?ref=${encodeURIComponent(id)}`);
}

function compose() {
    const channel = activeChannel();

    if (!channel) {
        print('no active channel selected', 'danger');
        setSuggestion('channels', 'select a channel first');
        return;
    }

    const recipients = channelRecipients(channel);

    if (!recipients.length) {
        print(`no recipients with public key available in ${channel.label}`, 'warning');
        print('Ontvangers moeten eerst key setup afronden.', 'muted');
        setSuggestion(canManageActiveChannel() ? 'add member' : 'members', canManageActiveChannel() ? 'add user to active channel' : 'show channel members');
        return;
    }

    state.shellLocation = 'channel';
    updatePrompt();
    state.mode = 'compose_recipient';
    state.compose.recipientRef = '';
    state.compose.recipientName = '';
    state.compose.expiryMinutes = '';
    state.compose.body = '';
    input.placeholder = 'recipient number or username';
    setInputHidden(false);

    print('compose mode', 'green');
    print(`target channel: ${channel.label}`, 'warning');
    print('Stap 1/3: kies ontvanger met public key.', 'muted');
    printRecipients(recipients);
    setSuggestion('1', 'first recipient');
}

function printRecipients(recipients) {
    const cards = recipients.map((member, index) => `
        <button type="button" class="terminal-card terminal-card-compact" data-command="${index + 1}">
            <strong>[${index + 1}] ${escapeHtml(member.username)}</strong>
            <span>role: ${escapeHtml(member.role)}</span>
            <span>key: active</span>
            <span class="muted">${escapeHtml(member.publicId.slice(0, 12))}...</span>
            <code>select ${index + 1}</code>
        </button>
    `).join('');

    printHtml(`<div class="terminal-grid">${cards}</div>`);
}

async function composeRecipient(value) {
    const channel = activeChannel();
    const recipients = channelRecipients(channel);
    const query = value.trim().toLowerCase();
    const recipientNumber = Number(query);
    const recipient = Number.isInteger(recipientNumber) && recipientNumber > 0
        ? recipients[recipientNumber - 1]
        : recipients.find(member => member.username.toLowerCase() === query || member.publicId.toLowerCase() === query);

    if (!recipient) {
        print('recipient not found in active channel', 'danger');
        setSuggestion('1', 'choose recipient number');
        return;
    }

    state.compose.recipientRef = recipient.publicId;
    state.compose.recipientName = recipient.username;
    print(`recipient set: ${recipient.username}`, 'green');

    try {
        state.compose.contact = await ddFetchContact(channel.id, recipient.publicId);
        state.compose.assessment = await ddAssessContact(state.user.publicId, state.compose.contact);
    } catch (error) {
        print(error.message || 'public key ophalen mislukt', 'danger');
        clearComposeSecrets();
        setIdlePrompt();
        setSuggestion('compose', 'restart compose');
        return;
    }

    printAssessment(recipient.username, state.compose.assessment);

    if (state.compose.assessment.status === 'changed') {
        state.mode = 'compose_keychange';
        input.placeholder = 'accept / cancel';
        print('Versturen geblokkeerd tot je de nieuwe fingerprint buiten Deaddrop hebt gecontroleerd.', 'danger');
        print('Typ accept als je hem gecontroleerd hebt, of cancel.', 'muted');
        setSuggestion('cancel', 'stop and verify first');
        return;
    }

    continueComposeToExpiry();
}

function continueComposeToExpiry() {
    const channel = activeChannel();
    state.mode = 'compose_expiry';
    input.placeholder = '15 / 60 / 1440 / 10080';

    const defaultExpiry = String(channel.defaultExpiryMinutes || 1440);
    print(`max expiry for channel: ${channel.maxExpiryMinutes || 10080}m`, 'muted');
    print('Stap 2/4: kies expiry.', 'muted');
    setSuggestion(defaultExpiry, 'channel default');
}

async function composeKeyChange(value) {
    if (value.trim().toLowerCase() !== 'accept') {
        print('typ accept na controle, of cancel', 'warning');
        return;
    }

    const { contact, assessment } = state.compose;
    await ddPinContact(state.user.publicId, contact.user_public_id, assessment.fingerprint, true);
    state.compose.assessment = { ...assessment, status: 'verified', previous: null };
    print('nieuwe fingerprint vastgelegd als gecontroleerd', 'green');
    continueComposeToExpiry();
}

function printAssessment(username, assessment) {
    const tone = assessment.status === 'verified' ? 'green' : (assessment.status === 'changed' ? 'danger' : 'warning');
    print(`fingerprint ${username}:`, 'muted');
    print(`  ${formatFingerprint(assessment.fingerprint).replace('\n', '\n  ')}`, tone);

    if (assessment.previous) {
        print(`  eerder: ${formatFingerprint(assessment.previous).replace('\n', ' ')}`, 'muted');
    }

    print(`  ${ddContactStatusText(assessment)}`, tone);
}

function composeExpiry(value) {
    const channel = activeChannel();
    const expiry = parseExpiry(value);
    const maxExpiry = Number(channel?.maxExpiryMinutes || 10080);

    if (!expiry || expiry > maxExpiry) {
        print(`invalid expiry. Gebruik max ${maxExpiry} minuten.`, 'danger');
        setSuggestion(String(channel?.defaultExpiryMinutes || 1440), 'channel default');
        return;
    }

    state.compose.expiryMinutes = String(expiry);
    state.mode = 'compose_body';
    input.placeholder = 'message body';
    setInputHidden(true);
    print(`expiry set: ${expiry}m`, 'green');
    print('Stap 3/4: typ bericht. Het wordt niet in terminal history getoond.', 'muted');
    setSuggestion('', '');
}

async function composeBody(value) {
    const body = value.trim();

    if (!body) {
        print('message empty', 'danger');
        return;
    }

    if (body.length > 3000) {
        print('message too long. max 3000 chars', 'danger');
        return;
    }

    state.compose.body = body;
    print('message captured: [hidden]', 'green');
    state.mode = 'compose_sign';
    input.placeholder = 'private pass, of: anon';
    setInputHidden(true);
    print('Stap 4/4: private pass om te ondertekenen. Typ anon om niet te ondertekenen:', 'muted');
    print('  de ontvanger ziet dan niet van wie het komt en kan het ook niet controleren.', 'muted');
    setSuggestion('', '');
}

async function composeSign(value) {
    const anonymous = value.trim().toLowerCase() === 'anon';

    if (!anonymous && !value) {
        print('private pass leeg', 'danger');
        return;
    }

    await submitComposedMessage(anonymous ? null : value);
}

async function submitComposedMessage(privatePass = null) {
    const channel = activeChannel();

    if (!channel) {
        print('no active channel selected', 'danger');
        setIdlePrompt();
        setInputHidden(false);
        return;
    }

    if (typeof sealMessage !== 'function' || typeof ddUnlockMyKeys !== 'function') {
        print('encryptie module niet geladen', 'danger');
        setIdlePrompt();
        setInputHidden(false);
        return;
    }

    try {
        const { contact, assessment } = state.compose;
        let sender = null;

        if (privatePass !== null) {
            print('unlocking signing key...', 'green');
            const unlocked = await ddUnlockMyKeys(privatePass, message => print(message.toLowerCase(), 'muted'));
            sender = { publicId: state.user.publicId, signKey: unlocked.opened.signKey };
        }

        if (assessment.status === 'new') {
            await ddPinContact(state.user.publicId, contact.user_public_id, assessment.fingerprint, false);
        }

        print(sender ? 'signing and encrypting client-side...' : 'encrypting client-side (unsigned)...', 'green');
        const encrypted = await sealMessage(state.compose.body, { publicId: contact.user_public_id, publicKey: contact.public_key }, sender);
        print('sealed payload ready', 'green');

        const form = document.createElement('form');
        form.method = 'post';
        form.action = route('actions/store_message.php');
        form.style.display = 'none';

        appendHidden(form, 'source', 'terminal');
        appendHidden(form, 'csrf_token', state.csrfToken);
        appendHidden(form, 'deaddrop_ref', channel.id);
        appendHidden(form, 'recipient_ref', state.compose.recipientRef);
        appendHidden(form, 'expiry_minutes', state.compose.expiryMinutes);
        appendHidden(form, 'encrypted_payload', encrypted.encrypted_payload);
        appendHidden(form, 'iv', encrypted.iv);
        appendHidden(form, 'salt', encrypted.salt);

        clearComposeSecrets();
        document.body.appendChild(form);
        form.submit();
    } catch (error) {
        console.error(error);
        print(error instanceof DeaddropWrongPassError ? 'private pass klopt niet. Compose afgebroken.' : 'encryptie mislukt. Probeer opnieuw.', 'danger');
        clearComposeSecrets();
        setIdlePrompt();
        setInputHidden(false);
        setSuggestion('compose', 'restart secure compose');
    }
}

function clearComposeSecrets() {
    state.compose.body = '';
    state.compose.contact = null;
    state.compose.assessment = null;
    state.verify.contact = null;
    state.verify.assessment = null;
    setInputHidden(false);
}

function setInputHidden(hidden) {
    input.type = hidden ? 'password' : 'text';
}

function openChannel() {
    const channel = activeChannel();

    if (!channel) {
        print('no active channel selected', 'danger');
        setSuggestion('channels', 'select a channel first');
        return;
    }

    window.location.href = route(`deaddrop.php?ref=${encodeURIComponent(channel.id)}`);
}

function openAdmin() {
    if (!isAdmin()) {
        print('admin access denied', 'danger');
        setSuggestion('status', 'show session status');
        return;
    }

    window.location.href = route('admin_channels.php');
}

function openAdminUsers() {
    if (!isAdmin()) {
        print('admin access denied', 'danger');
        setSuggestion('status', 'show session status');
        return;
    }

    window.location.href = route('admin_users.php');
}

function openAdminDevices() {
    if (!isAdmin()) {
        print('admin access denied', 'danger');
        setSuggestion('status', 'show session status');
        return;
    }

    window.location.href = route('admin_devices.php');
}

function openNodeAdmin() {
    if (!isSuperadmin()) {
        print('superadmin access denied', 'danger');
        setSuggestion('status', 'show session status');
        return;
    }

    window.location.href = route('node_admin.php');
}

function openNodeIdentity() {
    if (!isSuperadmin()) {
        print('superadmin access denied', 'danger');
        setSuggestion('status', 'show session status');
        return;
    }

    window.location.href = route('node_identity.php');
}

function isAdmin() {
    return ['admin', 'superadmin'].includes(String(state.user.role || '').toLowerCase());
}

function isSuperadmin() {
    return String(state.user.role || '').toLowerCase() === 'superadmin';
}

function canManageActiveChannel() {
    const channel = activeChannel();

    return isAdmin() || String(channel?.role || '').toLowerCase() === 'owner';
}

function startChannelCreate() {
    if (!isAdmin()) {
        print('admin access denied', 'danger');
        setSuggestion('status', 'show session status');
        return;
    }

    state.mode = 'channel_name';
    state.channelCreate.name = '';
    state.channelCreate.defaultExpiryMinutes = '';
    state.channelCreate.maxExpiryMinutes = '';
    promptEl.textContent = 'channel-name:';
    input.placeholder = 'bijv. alpha-team';
    print('create channel mode', 'green');
    print('Stap 1/3: kies een channelnaam tussen 3 en 80 tekens.', 'muted');
    setSuggestion('', '');
}

function setIdlePrompt() {
    state.mode = 'idle';
    updatePrompt();
    input.placeholder = 'type help';
    setInputHidden(false);
}

function channelCreateName(value) {
    const name = value.trim();

    if (name.length < 3 || name.length > 80) {
        print('invalid channel name. Gebruik 3 tot 80 tekens.', 'danger');
        return;
    }

    state.channelCreate.name = name;
    state.mode = 'channel_default_expiry';
    promptEl.textContent = 'default-expiry:';
    input.placeholder = '15 / 60 / 1440 / 10080';
    print(`channel name set: ${name}`, 'green');
    print('Stap 2/3: default expiry in minuten.', 'muted');
    setSuggestion('1440', '24 uur');
}

function channelCreateDefaultExpiry(value) {
    const expiry = parseExpiry(value);

    if (!expiry) {
        print('invalid default expiry. Gebruik 15, 60, 1440 of 10080.', 'danger');
        setSuggestion('1440', '24 uur');
        return;
    }

    state.channelCreate.defaultExpiryMinutes = String(expiry);
    state.mode = 'channel_max_expiry';
    promptEl.textContent = 'max-expiry:';
    input.placeholder = '60 / 1440 / 10080';
    print(`default expiry set: ${expiry}m`, 'green');
    print('Stap 3/3: max expiry in minuten.', 'muted');
    setSuggestion('10080', '7 dagen');
}

function channelCreateMaxExpiry(value) {
    const expiry = parseExpiry(value);
    const defaultExpiry = Number(state.channelCreate.defaultExpiryMinutes);

    if (!expiry || expiry < defaultExpiry) {
        print('invalid max expiry. Max moet minimaal gelijk zijn aan default expiry.', 'danger');
        setSuggestion(String(Math.max(defaultExpiry, 10080)), 'valid max expiry');
        return;
    }

    state.channelCreate.maxExpiryMinutes = String(expiry);
    print(`max expiry set: ${expiry}m`, 'green');
    print('creating channel...', 'green');
    submitChannelCreate();
}

function parseExpiry(value) {
    const normalized = value.trim().toLowerCase();
    const aliases = {
        '15': 15,
        '15m': 15,
        '60': 60,
        '1h': 60,
        '1440': 1440,
        '24h': 1440,
        '10080': 10080,
        '7d': 10080
    };

    return aliases[normalized] || 0;
}

function submitChannelCreate() {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = route('actions/create_channel.php');
    form.style.display = 'none';

    appendHidden(form, 'csrf_token', state.csrfToken);
    appendHidden(form, 'source', 'terminal');
    appendHidden(form, 'name', state.channelCreate.name);
    appendHidden(form, 'default_expiry_minutes', state.channelCreate.defaultExpiryMinutes);
    appendHidden(form, 'max_expiry_minutes', state.channelCreate.maxExpiryMinutes);

    document.body.appendChild(form);
    form.submit();
}

function startChannelUpdate() {
    const channel = activeChannel();

    if (!channel) {
        print('no active channel selected', 'danger');
        setSuggestion('channels', 'select a channel first');
        return;
    }

    if (!canManageActiveChannel()) {
        print('channel management denied. Alleen admin of owner kan instellingen aanpassen.', 'danger');
        setSuggestion('settings', 'show channel settings');
        return;
    }

    state.shellLocation = 'channel';
    updatePrompt();
    state.mode = 'channel_update_name';
    state.channelCreate.name = channel.label;
    state.channelCreate.defaultExpiryMinutes = String(channel.defaultExpiryMinutes || 1440);
    state.channelCreate.maxExpiryMinutes = String(channel.maxExpiryMinutes || 10080);
    promptEl.textContent = 'channel-name:';
    input.placeholder = channel.label;
    setInputHidden(false);
    print('update channel mode', 'green');
    print(`current channel: ${channel.label}`, 'warning');
    print('Stap 1/3: nieuwe naam. Druk enter met dezelfde naam om te behouden.', 'muted');
    setSuggestion(channel.label, 'current name');
}

function channelUpdateName(value) {
    const channel = activeChannel();
    const name = value.trim() || channel?.label || '';

    if (name.length < 3 || name.length > 80) {
        print('invalid channel name. Gebruik 3 tot 80 tekens.', 'danger');
        return;
    }

    state.channelCreate.name = name;
    state.mode = 'channel_update_default_expiry';
    promptEl.textContent = 'default-expiry:';
    input.placeholder = '15 / 60 / 1440 / 10080';
    print(`channel name set: ${name}`, 'green');
    print('Stap 2/3: default expiry in minuten.', 'muted');
    setSuggestion(state.channelCreate.defaultExpiryMinutes || '1440', 'current default');
}

function channelUpdateDefaultExpiry(value) {
    const expiry = parseExpiry(value);

    if (!expiry) {
        print('invalid default expiry. Gebruik 15, 60, 1440 of 10080.', 'danger');
        setSuggestion(state.channelCreate.defaultExpiryMinutes || '1440', 'current default');
        return;
    }

    state.channelCreate.defaultExpiryMinutes = String(expiry);
    state.mode = 'channel_update_max_expiry';
    promptEl.textContent = 'max-expiry:';
    input.placeholder = '60 / 1440 / 10080';
    print(`default expiry set: ${expiry}m`, 'green');
    print('Stap 3/3: max expiry in minuten.', 'muted');
    setSuggestion(state.channelCreate.maxExpiryMinutes || '10080', 'current max');
}

function channelUpdateMaxExpiry(value) {
    const expiry = parseExpiry(value);
    const defaultExpiry = Number(state.channelCreate.defaultExpiryMinutes);

    if (!expiry || expiry < defaultExpiry) {
        print('invalid max expiry. Max moet minimaal gelijk zijn aan default expiry.', 'danger');
        setSuggestion(String(Math.max(defaultExpiry, 10080)), 'valid max expiry');
        return;
    }

    state.channelCreate.maxExpiryMinutes = String(expiry);
    print(`max expiry set: ${expiry}m`, 'green');
    print('updating channel...', 'green');
    submitChannelUpdate();
}

function submitChannelUpdate() {
    const channel = activeChannel();

    if (!channel) {
        print('no active channel selected', 'danger');
        setIdlePrompt();
        return;
    }

    const form = document.createElement('form');
    form.method = 'post';
    form.action = route('actions/update_channel.php');
    form.style.display = 'none';

    appendHidden(form, 'csrf_token', state.csrfToken);
    appendHidden(form, 'source', 'terminal');
    appendHidden(form, 'deaddrop_ref', channel.id);
    appendHidden(form, 'name', state.channelCreate.name);
    appendHidden(form, 'default_expiry_minutes', state.channelCreate.defaultExpiryMinutes);
    appendHidden(form, 'max_expiry_minutes', state.channelCreate.maxExpiryMinutes);

    document.body.appendChild(form);
    form.submit();
}

function startUserCreate() {
    if (!isAdmin()) {
        print('admin access denied', 'danger');
        setSuggestion('status', 'show session status');
        return;
    }

    state.mode = 'user_create_username';
    state.userCreate.username = '';
    state.userCreate.password = '';
    promptEl.textContent = 'new-username:';
    input.placeholder = 'letters, numbers, . _ -';
    setInputHidden(false);
    print('create user mode', 'green');
    print('Stap 1/2: kies username. Nieuwe users krijgen normale user-role, geen admin.', 'muted');
    print('Toegestaan: 3-50 tekens, letters/cijfers/punt/underscore/streepje.', 'muted');
    setSuggestion('', '');
}

function userCreateUsername(value) {
    const username = value.trim();

    if (!/^[a-zA-Z0-9._-]{3,50}$/.test(username)) {
        print('invalid username. Gebruik 3-50 tekens: letters, cijfers, punt, underscore of streepje.', 'danger');
        return;
    }

    state.userCreate.username = username;
    state.mode = 'user_create_password';
    promptEl.textContent = 'temp-password:';
    input.placeholder = 'minimaal 12 tekens';
    setInputHidden(true);
    print(`username set: ${username}`, 'green');
    print('Stap 2/2: voer tijdelijk wachtwoord in. Wordt niet in terminal history getoond.', 'muted');
    setSuggestion('', '');
}

function userCreatePassword(value) {
    const password = value;

    if (password.length < 12) {
        print('password too short. Gebruik minimaal 12 tekens.', 'danger');
        return;
    }

    state.userCreate.password = password;
    print('temporary password captured: [hidden]', 'green');
    print('creating user...', 'green');
    submitUserCreate();
}

function submitUserCreate() {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = route('actions/create_user.php');
    form.style.display = 'none';

    appendHidden(form, 'csrf_token', state.csrfToken);
    appendHidden(form, 'source', 'terminal');
    appendHidden(form, 'username', state.userCreate.username);
    appendHidden(form, 'password', state.userCreate.password);

    state.userCreate.password = '';
    document.body.appendChild(form);
    form.submit();
}

function startDeviceCreate() {
    if (!isAdmin()) {
        print('admin access denied', 'danger');
        setSuggestion('status', 'show session status');
        return;
    }

    state.mode = 'device_create_label';
    state.deviceCreate.label = '';
    state.deviceCreate.userRef = '';
    state.deviceCreate.expiresHours = '24';
    promptEl.textContent = 'device-label:';
    input.placeholder = 'bijv. Pixel 7 - user';
    setInputHidden(false);
    print('create trusted device mode', 'green');
    print('Stap 1/3: geef het device een label.', 'muted');
    setSuggestion('', '');
}

function deviceCreateLabel(value) {
    const label = value.trim();

    if (label.length < 3 || label.length > 120) {
        print('invalid label. Gebruik 3 tot 120 tekens.', 'danger');
        return;
    }

    state.deviceCreate.label = label;
    state.mode = 'device_create_user';
    promptEl.textContent = 'device-user:';
    input.placeholder = 'any / number / username';
    print(`device label set: ${label}`, 'green');
    print('Stap 2/3: koppel aan user of typ any.', 'muted');
    printDeviceUserChoices();
    setSuggestion('any', 'device can be used by any active user');
}

function printDeviceUserChoices() {
    if (!state.deviceUsers.length) {
        print('Geen users beschikbaar. Gebruik any.', 'muted');
        return;
    }

    const cards = state.deviceUsers.slice(0, 8).map((user, index) => `
        <button type="button" class="terminal-card terminal-card-compact" data-command="${index + 1}">
            <strong>[${index + 1}] ${escapeHtml(user.username)}</strong>
            <span>role: ${escapeHtml(user.role)}</span>
            <span>key: ${user.hasKey ? 'active' : 'missing'}</span>
            <code>select ${index + 1}</code>
        </button>
    `).join('');

    printHtml(`<div class="terminal-grid">${cards}</div>`);
}

function resolveDeviceUser(value) {
    const query = value.trim().toLowerCase();

    if (query === '' || query === 'any' || query === 'none') {
        return null;
    }

    const userNumber = Number(query);

    if (Number.isInteger(userNumber) && userNumber > 0) {
        return state.deviceUsers[userNumber - 1] || false;
    }

    return state.deviceUsers.find(user => {
        return user.username.toLowerCase() === query || user.publicId.toLowerCase() === query;
    }) || false;
}

function deviceCreateUser(value) {
    const user = resolveDeviceUser(value);

    if (user === false) {
        print('user not found. Kies any, nummer, username of public id.', 'danger');
        setSuggestion('any', 'no fixed user binding');
        return;
    }

    state.deviceCreate.userRef = user ? user.publicId : '';
    state.mode = 'device_create_expiry';
    promptEl.textContent = 'expires-hours:';
    input.placeholder = '1 / 8 / 24 / 72';
    print(`device user binding: ${user ? user.username : 'any active user'}`, 'green');
    print('Stap 3/3: kies hoeveel uur de activatiecode geldig blijft.', 'muted');
    setSuggestion('24', 'default');
}

function deviceCreateExpiry(value) {
    const hours = Number(value.trim());

    if (![1, 8, 24, 72].includes(hours)) {
        print('invalid expiry. Gebruik 1, 8, 24 of 72.', 'danger');
        setSuggestion('24', 'default');
        return;
    }

    state.deviceCreate.expiresHours = String(hours);
    print(`activation expiry set: ${hours}h`, 'green');
    print('creating activation code...', 'green');
    submitDeviceActivationCreate();
}

function submitDeviceActivationCreate() {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = route('actions/create_device_activation.php');
    form.style.display = 'none';

    appendHidden(form, 'csrf_token', state.csrfToken);
    appendHidden(form, 'source', 'terminal');
    appendHidden(form, 'label', state.deviceCreate.label);
    appendHidden(form, 'user_ref', state.deviceCreate.userRef);
    appendHidden(form, 'expires_hours', state.deviceCreate.expiresHours);

    document.body.appendChild(form);
    form.submit();
}

function appendHidden(form, name, value) {
    const inputNode = document.createElement('input');
    inputNode.type = 'hidden';
    inputNode.name = name;
    inputNode.value = value;
    form.appendChild(inputNode);
}

function startMemberAdd(username = '') {
    if (!canManageActiveChannel()) {
        print('channel management denied. Alleen admin of owner kan members toevoegen.', 'danger');
        setSuggestion('members', 'show channel members');
        return;
    }

    const channel = activeChannel();

    if (!channel) {
        print('no active channel selected', 'danger');
        setSuggestion('channels', 'select a channel first');
        return;
    }

    const cleanUsername = username.trim();
    const availableUsers = availableUsersForActiveChannel(channel);

    if (cleanUsername) {
        const user = resolveAvailableUser(cleanUsername, availableUsers);

        if (!user) {
            print(`user not available for ${channel.label}: ${cleanUsername}`, 'danger');
            print('Gebruik add member zonder waarde om de picker te openen.', 'muted');
            setSuggestion('add member', 'open member picker');
            return;
        }

        submitMemberAdd(user);
        return;
    }

    state.shellLocation = 'channel';
    updatePrompt();
    state.mode = 'member_username';
    state.memberAdd.username = '';
    promptEl.textContent = 'member-username:';
    input.placeholder = 'number / username / public id';
    print(`add member mode`, 'green');
    print(`target channel: ${channel.label}`, 'warning');
    print('Kies met nummer, username, public id, of klik een kaart.', 'muted');
    print('Key missing betekent: user kan member zijn, maar nog geen E2EE berichten ontvangen.', 'muted');

    if (availableUsers.length) {
        printAvailableUsers(availableUsers);
        setSuggestion('1', 'first available user');
        return;
    }

    print('Geen users beschikbaar om toe te voegen.', 'warning');
    setSuggestion('', '');
}

function printAvailableUsers(users) {
    const cards = users.map((user, index) => `
        <button type="button" class="terminal-card terminal-card-compact" data-command="${index + 1}">
            <strong>[${index + 1}] ${escapeHtml(user.username)}</strong>
            <span>role: ${escapeHtml(user.role)}</span>
            <span>key: ${user.hasKey ? 'active' : 'missing'}</span>
            <span class="muted">${escapeHtml(user.publicId.slice(0, 12))}...</span>
            <code>select ${index + 1}</code>
        </button>
    `).join('');

    printHtml(`<div class="terminal-grid">${cards}</div>`);
}

function memberAddUsername(value) {
    const query = value.trim();

    if (!query) {
        print('member selection empty', 'danger');
        return;
    }

    const availableUsers = availableUsersForActiveChannel();
    const user = resolveAvailableUser(query, availableUsers);

    if (!user) {
        print(`member not found in available users: ${query}`, 'danger');

        if (availableUsers.length) {
            print('Kies een nummer uit de lijst of typ de exacte username.', 'muted');
            setSuggestion('1', 'first available user');
        } else {
            print('Geen users beschikbaar om toe te voegen.', 'warning');
            setSuggestion('members', 'show existing members');
        }

        return;
    }

    submitMemberAdd(user);
}

function submitMemberAdd(user) {
    const channel = activeChannel();

    if (!channel) {
        print('no active channel selected', 'danger');
        setIdlePrompt();
        return;
    }

    print(`target channel: ${channel.label}`, 'warning');
    print(`selected member: ${user.username}`, 'green');

    if (!user.hasKey) {
        print('note: key missing, deze user moet key setup afronden voor E2EE ontvangst.', 'warning');
    }

    print('adding member...', 'green');

    const form = document.createElement('form');
    form.method = 'post';
    form.action = route('actions/add_channel_member.php');
    form.style.display = 'none';

    appendHidden(form, 'csrf_token', state.csrfToken);
    appendHidden(form, 'source', 'terminal');
    appendHidden(form, 'deaddrop_ref', channel.id);
    appendHidden(form, 'username', user.username);

    document.body.appendChild(form);
    form.submit();
}

function startMemberRemove(value = '') {
    if (!canManageActiveChannel()) {
        print('channel management denied. Alleen admin of owner kan members verwijderen.', 'danger');
        setSuggestion('members', 'show channel members');
        return;
    }

    const channel = activeChannel();

    if (!channel) {
        print('no active channel selected', 'danger');
        setSuggestion('channels', 'select a channel first');
        return;
    }

    const removableMembers = removableMembersForActiveChannel(channel);
    const cleanValue = value.trim();

    if (cleanValue) {
        const member = resolveRemovableMember(cleanValue, removableMembers);

        if (!member) {
            print(`member not removable in ${channel.label}: ${cleanValue}`, 'danger');
            print('Owners en jezelf verwijderen we niet via deze veilige flow.', 'muted');
            setSuggestion('remove member', 'open member picker');
            return;
        }

        submitMemberRemove(member);
        return;
    }

    state.shellLocation = 'channel';
    updatePrompt();
    state.mode = 'member_remove';
    state.memberRemove.memberRef = '';
    promptEl.textContent = 'remove-member:';
    input.placeholder = 'number / username / public id';
    print('remove member mode', 'green');
    print(`target channel: ${channel.label}`, 'warning');
    print('Kies met nummer, username, public id, of klik een kaart.', 'muted');
    print('Owners en jezelf worden niet in deze lijst gezet.', 'muted');

    if (removableMembers.length) {
        printRemovableMembers(removableMembers);
        setSuggestion('1', 'first removable member');
        return;
    }

    print('Geen removable members beschikbaar.', 'warning');
    setSuggestion('members', 'show channel members');
}

function printRemovableMembers(members) {
    const cards = members.map((member, index) => `
        <button type="button" class="terminal-card terminal-card-compact" data-command="${index + 1}">
            <strong>[${index + 1}] ${escapeHtml(member.username)}</strong>
            <span>role: ${escapeHtml(member.role)}</span>
            <span>key: ${member.hasKey ? 'active' : 'missing'}</span>
            <span class="muted">${escapeHtml(member.publicId.slice(0, 12))}...</span>
            <code>remove ${index + 1}</code>
        </button>
    `).join('');

    printHtml(`<div class="terminal-grid">${cards}</div>`);
}

function memberRemove(value) {
    const query = value.trim();

    if (!query) {
        print('member selection empty', 'danger');
        return;
    }

    const removableMembers = removableMembersForActiveChannel();
    const member = resolveRemovableMember(query, removableMembers);

    if (!member) {
        print(`member not found in removable members: ${query}`, 'danger');
        setSuggestion(removableMembers.length ? '1' : 'members', removableMembers.length ? 'first removable member' : 'show members');
        return;
    }

    submitMemberRemove(member);
}

function submitMemberRemove(member) {
    const channel = activeChannel();

    if (!channel) {
        print('no active channel selected', 'danger');
        setIdlePrompt();
        return;
    }

    print(`target channel: ${channel.label}`, 'warning');
    print(`removing member: ${member.username}`, 'green');

    const form = document.createElement('form');
    form.method = 'post';
    form.action = route('actions/remove_channel_member.php');
    form.style.display = 'none';

    appendHidden(form, 'csrf_token', state.csrfToken);
    appendHidden(form, 'source', 'terminal');
    appendHidden(form, 'deaddrop_ref', channel.id);
    appendHidden(form, 'member_ref', member.publicId);
    appendHidden(form, 'confirm_remove', '1');

    document.body.appendChild(form);
    form.submit();
}

async function fingerprintCommand(username) {
    if (!username) {
        try {
            const me = await ddFetchMyKeys();
            const fingerprint = await keyFingerprint(me.record.public_key, me.record.signing_public_key);
            print('jouw fingerprint:', 'green');
            print(`  ${formatFingerprint(fingerprint).replace('\n', '\n  ')}`, 'green');
            print('Lees deze voor aan je contacten, buiten Deaddrop om.', 'muted');
        } catch (error) {
            print(error.message || 'fingerprint ophalen mislukt', 'danger');
        }
        setSuggestion('inbox', 'check drops');
        return;
    }

    const contact = await contactInActiveChannel(username);

    if (!contact) {
        return;
    }

    printAssessment(contact.username, await ddAssessContact(state.user.publicId, contact));
    setSuggestion(`verify ${contact.username}`, 'mark as checked');
}

async function contactInActiveChannel(username) {
    const channel = activeChannel();
    const member = channel?.memberList?.find(item => item.username.toLowerCase() === username.toLowerCase());

    if (!channel || !member) {
        print(`member not found in active channel: ${username}`, 'danger');
        setSuggestion('members', 'show channel members');
        return null;
    }

    try {
        return await ddFetchContact(channel.id, member.publicId);
    } catch (error) {
        print(error.message || 'public key ophalen mislukt', 'danger');
        return null;
    }
}

async function startVerify(username) {
    if (!username) {
        print('usage: verify <username>', 'danger');
        setSuggestion('members', 'show channel members');
        return;
    }

    const contact = await contactInActiveChannel(username);

    if (!contact) {
        return;
    }

    const assessment = await ddAssessContact(state.user.publicId, contact);
    printAssessment(contact.username, assessment);
    state.verify.contact = contact;
    state.verify.assessment = assessment;
    state.mode = 'verify_confirm';
    input.placeholder = 'yes / cancel';
    print(`Heb je deze fingerprint met ${contact.username} vergeleken, persoonlijk of telefonisch? Typ yes.`, 'warning');
    setSuggestion('cancel', 'only confirm after checking');
}

async function verifyConfirm(value) {
    if (value.trim().toLowerCase() !== 'yes') {
        print('typ yes of cancel', 'warning');
        return;
    }

    const { contact, assessment } = state.verify;
    await ddPinContact(state.user.publicId, contact.user_public_id, assessment.fingerprint, true);
    print(`${contact.username}: gecontroleerd`, 'green');
    clearComposeSecrets();
    setIdlePrompt();
    setSuggestion('compose', 'send a signed drop');
}

function openKeys() {
    window.location.href = route('profile.php');
}

function whoami() {
    const channel = activeChannel();
    print('current session:', 'green');
    print(`  node: ${state.user.username || 'unknown'}`);
    print(`  public id: ${state.user.publicId || 'unknown'}`);
    print(`  role: ${state.user.role || 'unknown'}`);
    print(`  channel: ${channel ? channel.label : 'none'}`);
    print('  sender name in drops: never exposed');
    setSuggestion('inbox', 'check drops');
}

function status() {
    print('security status', 'green');
    print('  system name: DEADDROP');
    print('  logged in: yes');
    print(`  channels: ${state.channels.length}`);
    print(`  waiting drops: ${state.unopenedCount}`);
    print('  encryption: browser WebCrypto');
    print('  message encryption: RSA-OAEP 4096 + AES-256-GCM per drop');
    print('  sender authentication: ECDSA P-256 signature inside the envelope');
    print('  read model: burn after read (server wipes ciphertext)');
    print('  private pass transport: disabled');
    print('  server plaintext storage: disabled');
    print(`  trusted device gate: ${state.deviceTrustRequired ? 'required' : 'not required'}`);
    print(`  trusted device: ${state.trustedDevice ? state.trustedDevice.label : 'none'}`);
    print('  terminal routing: same-origin relative');
    print('  configured app url: ' + state.appUrl);
    print('');
    print('Terminal commands gebruiken echte database-data en sturen door naar de bestaande encrypted flows.', 'muted');
    setSuggestion('inbox', 'return to drops');
}

function clearScreen() {
    output.innerHTML = '';
    setSuggestion('help', 'show commands');
}

function logout() {
    window.location.href = route('logout.php');
}

function openUi() {
    window.location.href = route('dashboard.php');
}

async function handleIdle(command) {
    const [base, ...args] = command.trim().split(/\s+/);
    const arg = args.join(' ');

    switch ((base || '').toLowerCase()) {
        case 'help':
            help();
            break;
        case 'channels':
            channels();
            break;
        case 'back':
            backToRoot();
            break;
        case 'cd':
            if (arg === '..' || arg === '~' || arg === '/') {
                backToRoot();
            } else if (arg) {
                useChannel(arg);
            } else {
                print('usage: cd <channel> or cd ..', 'danger');
                setSuggestion('channels', 'show channels');
            }
            break;
        case 'members':
            members();
            break;
        case 'settings':
            channelSettings();
            break;
        case 'use':
            if (!arg) {
                print('usage: use <channel>', 'danger');
                setSuggestion('channels', 'show authorized channels');
            } else {
                useChannel(arg);
            }
            break;
        case 'inbox':
            inbox();
            break;
        case 'open':
            if (!arg) {
                print('usage: open <drop_id>', 'danger');
                setSuggestion('inbox', 'find drop id');
            } else {
                openDrop(arg);
            }
            break;
        case 'compose':
            if (!arg || arg === 'message') {
                compose();
            } else {
                print('usage: compose', 'danger');
                setSuggestion('compose', 'start encrypted compose');
            }
            break;
        case 'channel':
            openChannel();
            break;
        case 'admin':
            openAdmin();
            break;
        case 'users':
            openAdminUsers();
            break;
        case 'devices':
            openAdminDevices();
            break;
        case 'node':
            openNodeAdmin();
            break;
        case 'identity':
            openNodeIdentity();
            break;
        case 'add':
            if (args[0] === 'member') {
                startMemberAdd(args.slice(1).join(' '));
            } else {
                print('usage: add member <username>', 'danger');
                setSuggestion('add member', 'add user to active channel');
            }
            break;
        case 'remove':
            if (args[0] === 'member') {
                startMemberRemove(args.slice(1).join(' '));
            } else {
                print('usage: remove member <username|number>', 'danger');
                setSuggestion('remove member', 'remove user from active channel');
            }
            break;
        case 'set':
            if (arg === 'channel') {
                startChannelUpdate();
            } else {
                print('usage: set channel', 'danger');
                setSuggestion('set channel', 'update active channel');
            }
            break;
        case 'create':
            if (arg === 'channel') {
                startChannelCreate();
            } else if (arg === 'user') {
                startUserCreate();
            } else if (arg === 'device') {
                startDeviceCreate();
            } else {
                print('usage: create channel | create user | create device', 'danger');
                setSuggestion(isAdmin() ? 'create device' : 'help', 'admin device management');
            }
            break;
        case 'whoami':
            whoami();
            break;
        case 'fingerprint':
            await fingerprintCommand(arg);
            break;
        case 'verify':
            await startVerify(arg);
            break;
        case 'keys':
            openKeys();
            break;
        case 'status':
            status();
            break;
        case 'ui':
        case 'dashboard':
            openUi();
            break;
        case 'logout':
        case 'exit':
            logout();
            break;
        case 'clear':
            clearScreen();
            break;
        case '':
            break;
        default:
            print(`unknown command: ${base}`, 'danger');
            print('type help', 'muted');
            setSuggestion('help', 'show commands');
    }
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

document.getElementById('commandForm').addEventListener('submit', async event => {
    event.preventDefault();

    const value = input.value;
    input.value = '';

    if (state.mode !== 'idle' && value.trim().toLowerCase() === 'cancel') {
        print('cancel', 'warning');
        clearComposeSecrets();
        setIdlePrompt();
        setSuggestion('help', 'show commands');
        return;
    }

    if (state.mode === 'idle') {
        print(`${promptText()} ${value}`, 'green');
        await handleIdle(value);
        return;
    }

    if (state.mode === 'channel_name') {
        print(`channel-name: ${value}`, 'green');
        channelCreateName(value);
        return;
    }

    if (state.mode === 'channel_default_expiry') {
        print(`default-expiry: ${value}`, 'green');
        channelCreateDefaultExpiry(value);
        return;
    }

    if (state.mode === 'channel_max_expiry') {
        print(`max-expiry: ${value}`, 'green');
        channelCreateMaxExpiry(value);
        return;
    }

    if (state.mode === 'channel_update_name') {
        print(`channel-name: ${value}`, 'green');
        channelUpdateName(value);
        return;
    }

    if (state.mode === 'channel_update_default_expiry') {
        print(`default-expiry: ${value}`, 'green');
        channelUpdateDefaultExpiry(value);
        return;
    }

    if (state.mode === 'channel_update_max_expiry') {
        print(`max-expiry: ${value}`, 'green');
        channelUpdateMaxExpiry(value);
        return;
    }

    if (state.mode === 'user_create_username') {
        print(`new-username: ${value}`, 'green');
        userCreateUsername(value);
        return;
    }

    if (state.mode === 'user_create_password') {
        print('temp-password: [hidden]', 'green');
        userCreatePassword(value);
        return;
    }

    if (state.mode === 'device_create_label') {
        print(`device-label: ${value}`, 'green');
        deviceCreateLabel(value);
        return;
    }

    if (state.mode === 'device_create_user') {
        print(`device-user: ${value}`, 'green');
        deviceCreateUser(value);
        return;
    }

    if (state.mode === 'device_create_expiry') {
        print(`expires-hours: ${value}`, 'green');
        deviceCreateExpiry(value);
        return;
    }

    if (state.mode === 'member_username') {
        print(`member-username: ${value}`, 'green');
        memberAddUsername(value);
        return;
    }

    if (state.mode === 'member_remove') {
        print(`remove-member: ${value}`, 'green');
        memberRemove(value);
        return;
    }

    if (state.mode === 'compose_recipient') {
        print(`recipient: ${value}`, 'green');
        await composeRecipient(value);
        return;
    }

    if (state.mode === 'compose_keychange') {
        print(`key-change: ${value}`, 'green');
        await composeKeyChange(value);
        return;
    }

    if (state.mode === 'compose_sign') {
        print(value.trim().toLowerCase() === 'anon' ? 'sign: anon' : 'sign: [hidden]', 'green');
        await composeSign(value);
        return;
    }

    if (state.mode === 'verify_confirm') {
        print(`confirm: ${value}`, 'green');
        await verifyConfirm(value);
        return;
    }

    if (state.mode === 'compose_expiry') {
        print(`expiry: ${value}`, 'green');
        composeExpiry(value);
        return;
    }

    if (state.mode === 'compose_body') {
        print('message: [hidden]', 'green');
        await composeBody(value);
        return;
    }
});

input.addEventListener('keydown', event => {
    if (event.key !== 'Tab' || !state.suggestion) {
        return;
    }

    event.preventDefault();
    input.value = state.suggestion;
    input.focus();
});

document.addEventListener('click', event => {
    const button = event.target.closest('[data-command]');

    if (!button) {
        return;
    }

    const command = button.getAttribute('data-command') || '';

    if (!command) {
        input.focus();
        return;
    }

    input.value = command;
    input.focus();
});

document.querySelector('.terminal').addEventListener('click', focusInput);

updatePrompt();
boot();
focusInput();
