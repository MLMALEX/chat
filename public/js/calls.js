(() => {
    'use strict';

    const userId = Number(document.body.dataset.callUserId || 0);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!userId || !csrf) return;

    let currentCall = null;
    let room = null;
    let timer = null;
    let seconds = 0;
    let ringtone = null;

    const api = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.headers || {}),
            },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'Request failed');
        return data;
    };

    const conversationId = () => {
        const prefix = '/chatify/';
        const pos = location.pathname.indexOf(prefix);
        if (pos === -1) return null;

        const id = location.pathname.slice(pos + prefix.length).split('/')[0];
        return id || null;
    };

    const createUi = () => {
        const launcher = document.createElement('div');
        launcher.id = 'qsn-call-launcher';
        launcher.innerHTML = `
            <button class="qsn-call-btn" data-call-type="audio" title="Audio call">☎</button>
            <button class="qsn-call-btn" data-call-type="video" title="Video call">▣</button>
        `;
        document.body.appendChild(launcher);

        const overlay = document.createElement('div');
        overlay.id = 'qsn-call-overlay';
        overlay.innerHTML = `
            <div class="qsn-call-card">
                <div class="qsn-call-name" id="qsn-call-name">Call</div>
                <div class="qsn-call-status" id="qsn-call-status">Connecting…</div>
                <div class="qsn-call-videos" id="qsn-call-videos">
                    <div id="qsn-remote-media"></div>
                    <video id="qsn-local-video" autoplay muted playsinline></video>
                </div>
                <div class="qsn-call-controls">
                    <button class="qsn-control qsn-accept qsn-hidden" id="qsn-call-accept">Answer</button>
                    <button class="qsn-control qsn-reject qsn-hidden" id="qsn-call-reject">Decline</button>
                    <button class="qsn-control qsn-neutral qsn-hidden" id="qsn-call-mute">Mute</button>
                    <button class="qsn-control qsn-neutral qsn-hidden" id="qsn-call-camera">Camera</button>
                    <button class="qsn-control qsn-end qsn-hidden" id="qsn-call-end">End</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        launcher.addEventListener('click', e => {
            const type = e.target.closest('[data-call-type]')?.dataset.callType;
            if (type) startCall(type);
        });
        document.getElementById('qsn-call-accept').onclick = acceptCall;
        document.getElementById('qsn-call-reject').onclick = rejectCall;
        document.getElementById('qsn-call-end').onclick = endCall;
        document.getElementById('qsn-call-mute').onclick = toggleMute;
        document.getElementById('qsn-call-camera').onclick = toggleCamera;

        setInterval(() => {
            launcher.style.display = conversationId() && !currentCall ? 'flex' : 'none';
        }, 400);
    };

    const setControls = mode => {
        const ids = ['accept', 'reject', 'mute', 'camera', 'end'];
        ids.forEach(id => document.getElementById('qsn-call-' + id).classList.add('qsn-hidden'));
        if (mode === 'incoming') {
            document.getElementById('qsn-call-accept').classList.remove('qsn-hidden');
            document.getElementById('qsn-call-reject').classList.remove('qsn-hidden');
        } else if (mode === 'calling') {
            document.getElementById('qsn-call-end').classList.remove('qsn-hidden');
        } else if (mode === 'active') {
            document.getElementById('qsn-call-mute').classList.remove('qsn-hidden');
            document.getElementById('qsn-call-end').classList.remove('qsn-hidden');
            if (currentCall?.type === 'video') document.getElementById('qsn-call-camera').classList.remove('qsn-hidden');
        }
    };

    const show = (name, status, mode) => {
        document.getElementById('qsn-call-name').textContent = name || 'Call';
        document.getElementById('qsn-call-status').textContent = status;
        document.getElementById('qsn-call-overlay').classList.add('qsn-open');
        document.getElementById('qsn-call-videos').classList.toggle('qsn-hidden', currentCall?.type !== 'video');
        setControls(mode);
    };

    const closeUi = async () => {
        stopRingtone();
        if (timer) clearInterval(timer);
        timer = null;
        seconds = 0;
        if (room) {
            const oldRoom = room;
            room = null;
            await oldRoom.disconnect().catch(() => {});
        }
        document.getElementById('qsn-remote-media').innerHTML = '';
        document.getElementById('qsn-local-video').srcObject = null;
        document.getElementById('qsn-call-overlay').classList.remove('qsn-open');
        currentCall = null;
    };

    const startCall = async type => {
        const id = conversationId();
        if (!id || currentCall) return;
        try {
            const data = await api('/calls', {
                method: 'POST',
                body: JSON.stringify({ conversation_id: id, type }),
            });
            currentCall = { id: data.call_id, type, scope: data.scope || 'direct', role: 'caller', name: data.title || data.callee?.name || 'User' };
            show(currentCall.name, 'Calling…', 'calling');
        } catch (e) {
            alert('Call could not be started: ' + e.message);
        }
    };

    const incomingCall = data => {
        if (currentCall) return;
        currentCall = { id: data.call_id, type: data.type, scope: data.scope || 'direct', role: 'callee', name: data.title || data.caller?.name || 'User' };
        show(currentCall.name, (data.scope === 'group' ? 'Incoming group ' : 'Incoming ') + (data.type === 'video' ? 'video call' : 'audio call'), 'incoming');
        try {
            ringtone = new Audio('/vendor/chatify/sounds/incoming.mp3');
            ringtone.loop = true;
            ringtone.play().catch(() => {});
        } catch (_) {}
    };

    const stopRingtone = () => {
        if (!ringtone) return;
        ringtone.pause();
        ringtone.currentTime = 0;
        ringtone = null;
    };

    const acceptCall = async () => {
        if (!currentCall) return;
        stopRingtone();
        try {
            await api('/calls/' + currentCall.id + '/respond', {
                method: 'POST',
                body: JSON.stringify({ action: 'accept' }),
            });
            await joinRoom();
        } catch (e) {
            alert('Could not answer: ' + e.message);
            await closeUi();
        }
    };

    const rejectCall = async () => {
        if (!currentCall) return;
        const id = currentCall.id;
        stopRingtone();
        try {
            await api('/calls/' + id + '/respond', {
                method: 'POST',
                body: JSON.stringify({ action: 'reject' }),
            });
        } finally {
            await closeUi();
        }
    };

    const endCall = async () => {
        if (!currentCall) return;
        const id = currentCall.id;
        try {
            await api('/calls/' + id + '/end', { method: 'POST', body: '{}' });
        } catch (_) {}
        await closeUi();
    };

    const joinRoom = async () => {
        if (!currentCall || !window.LivekitClient) throw new Error('LiveKit client is not loaded.');

        const credentials = await api('/calls/' + currentCall.id + '/token', { method: 'POST', body: '{}' });
        const LK = window.LivekitClient;

        room = new LK.Room({ adaptiveStream: true, dynacast: true });

        room.on(LK.RoomEvent.TrackSubscribed, track => {
            const el = track.attach();
            el.autoplay = true;
            el.playsInline = true;
            if (track.kind === LK.Track.Kind.Video) {
                document.getElementById('qsn-remote-media').appendChild(el);
            } else {
                el.style.display = 'none';
                document.body.appendChild(el);
            }
        });

        room.on(LK.RoomEvent.TrackUnsubscribed, track => {
            track.detach().forEach(el => el.remove());
        });

        room.on(LK.RoomEvent.AudioPlaybackStatusChanged, () => {
            if (!room?.canPlaybackAudio) {
                document.getElementById('qsn-call-status').textContent = 'Click anywhere to enable sound';
            }
        });

        await room.connect(credentials.server_url, credentials.participant_token);
        await room.startAudio().catch(() => {});
        await room.localParticipant.setMicrophoneEnabled(true);

        if (currentCall.type === 'video') {
            const publication = await room.localParticipant.setCameraEnabled(true);
            if (publication?.track) publication.track.attach(document.getElementById('qsn-local-video'));
        }

        show(currentCall.name, '00:00', 'active');
        seconds = 0;
        timer = setInterval(() => {
            seconds++;
            const mm = String(Math.floor(seconds / 60)).padStart(2, '0');
            const ss = String(seconds % 60).padStart(2, '0');
            document.getElementById('qsn-call-status').textContent = mm + ':' + ss;
        }, 1000);
    };

    const toggleMute = async e => {
        if (!room) return;
        const enabled = !room.localParticipant.isMicrophoneEnabled;
        await room.localParticipant.setMicrophoneEnabled(enabled);
        e.target.textContent = enabled ? 'Mute' : 'Unmute';
    };

    const toggleCamera = async e => {
        if (!room) return;
        const enabled = !room.localParticipant.isCameraEnabled;
        await room.localParticipant.setCameraEnabled(enabled);
        e.target.textContent = enabled ? 'Camera off' : 'Camera on';
    };

    const waitForEcho = () => {
        if (!window.Echo) {
            setTimeout(waitForEcho, 250);
            return;
        }
        window.Echo.private('App.Models.User.' + userId)
            .listen('.call.incoming', incomingCall)
            .listen('.call.accepted', async data => {
                if (currentCall?.id !== data.call_id) return;
                try { await joinRoom(); } catch (e) { alert('Could not connect: ' + e.message); await closeUi(); }
            })
            .listen('.call.rejected', async data => {
                if (currentCall?.id !== data.call_id) return;
                alert('Call declined');
                await closeUi();
            })
            .listen('.call.participant_joined', data => {
                if (currentCall?.id !== data.call_id || !room) return;
            })
            .listen('.call.participant_left', data => {
                if (currentCall?.id !== data.call_id || !room) return;
            })
            .listen('.call.ended', async data => {
                if (currentCall?.id !== data.call_id) return;
                await closeUi();
            });
    };

    window.addEventListener('beforeunload', () => {
        if (room) room.disconnect();
    });

    createUi();
    waitForEcho();
})();
