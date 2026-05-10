let AppState = {
    user: null,
    isLoggedIn: false,
    isGuest: false,
    isGameActive: false,
    isVsAI: false,
    aiDifficulty: 'easy',
    currentRound: 1,
    maxRounds: 5,
    reactionTimes: [],
    currentScore: 0,
    combo: 0,
    gameIntervals: [],
    roundTimer: null,
    isUserReady: false,
    isOpponentReady: false,
    matchFound: false,
    roomPlayers: [],
    roomSpectators: [],
    maxPlayers: 4
};

const els = {};

const XP_REWARDS = { WIN: 500, LOSE: 100 };
const LEVEL_TABLE = [
    [1, 0], [2, 200], [3, 600], [4, 1100], [5, 1700],
    [6, 2500], [7, 3500], [8, 4700], [9, 6200], [10, 8000],
    [11, 10100], [12, 12500], [13, 15200], [14, 18200], [15, 21500],
    [16, 25100], [17, 29000], [18, 33200], [19, 37700], [20, 45000]
];
const ICON_UNLOCKS = {
    1: { icon: 'fa-user', name: 'Recruit' },
    2: { icon: 'fa-robot', name: 'Bot Fighter' },
    4: { icon: 'fa-cat', name: 'Swift Cat' },
    6: { icon: 'fa-dragon', name: 'Dragonborn' },
    8: { icon: 'fa-skull', name: 'Reaper' },
    10: { icon: 'fa-hat-wizard', name: 'Wizard' },
    15: { icon: 'fa-fire', name: 'Inferno' },
    20: { icon: 'fa-crown', name: 'Legend' }
};

// Jika web diakses via HTTPS (seperti di Railway), gunakan WSS. Jika lokal, gunakan WS.
const protocol = window.location.protocol === 'https:' ? 'wss://' : 'ws://';

// Deteksi host otomatis untuk mode lokal vs produksi
const wsHost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1'
    ? 'localhost:8080'
    : window.location.host;

const socket = new WebSocket(protocol + wsHost);

// ─── WEBSOCKET EVENT LISTENERS (Global) ──────────────────────────────────────

socket.onopen = () => {
    console.log("[WS] Terhubung ke Server!");
    if (els.serverStatus) els.serverStatus.textContent = "ONLINE";

    // Jika user sudah login (berdasarkan sessionStorage), umumkan kehadiran ke server.
    const currentUser = getCurrentUser();
    if (currentUser) {
        socket.send(JSON.stringify({
            type: 'JOIN',
            username: currentUser.username,
            icon: currentUser.icon || 'fa-user',
            isGuest: currentUser.type === 'guest'
        }));
    }
};

socket.onmessage = (event) => {
    let payload;
    try {
        payload = JSON.parse(event.data);
    } catch (e) {
        console.error("[WS] Gagal parse pesan:", event.data);
        return;
    }
    console.log("[WS] Pesan masuk:", payload);

    switch (payload.type) {

        // ── Chat ─────────────────────────────────────────────
        case 'CHAT_MESSAGE':
            Chat.render(payload);
            break;

        // ── Game: Server mengatur spawn item ─────────────────
        case 'SPAWN_ITEMS':
            Game.handleSpawnItems(payload);
            break;

        // ── Game: Hasil klik dikonfirmasi server ──────────────
        case 'SCORE_UPDATE':
            Game.handleScoreUpdate(payload);
            break;

        // ── Game: Item kedaluwarsa di server ─────────────────
        case 'ITEM_EXPIRED':
            Game.handleItemExpired(payload);
            break;

        // ── Game: Alur ronde dikontrol server ─────────────────
        case 'ROUND_UPDATE':
            Game.handleRoundUpdate(payload);
            break;

        case 'ROUND_RESULT':
            Game.handleRoundResult(payload);
            break;

        case 'GAME_OVER':
            Game.handleGameOver(payload);
            break;

        case 'START_GAME':
            // Server memberi sinyal semua pemain siap → mulai game
            Game._hideReadyAndStart();
            break;

        case 'WAIT':
            // Server meminta client standby (jeda antar ronde)
            Game.setStateWait('', '');
            break;

        // ── Room & Matchmaking ────────────────────────────────
        case 'PLAYER_LIST':
            AppState.roomPlayers = payload.players || [];
            UI.renderRoomSlots();
            break;

        case 'MATCH_FOUND':
            AppState.matchFound = true;
            break;

        case 'PLAYER_READY_UPDATE':
            // Tandai kartu pemain lain jadi hijau di ready overlay
            const oppIdx = AppState.roomPlayers.findIndex(p => p.username === payload.username);
            if (oppIdx !== -1) {
                const dot = document.getElementById(`ro-dot-${oppIdx}`);
                const txt = document.getElementById(`ro-text-${oppIdx}`);
                if (dot) { dot.style.background = '#43A047'; dot.style.boxShadow = '0 0 10px #43A047'; }
                if (txt) txt.textContent = 'READY ✓';
            }
            break;

        // ── Auth (opsional — untuk migrasi auth ke WS) ────────
        case 'AUTH_RESULT':
        case 'REGISTER_RESULT':
            // Placeholder: saat ini auth masih via localStorage.
            // Tangani di sini ketika migrasi auth ke WS dilakukan.
            console.log("[WS] Auth result:", payload);
            break;

        // ── Leaderboard dari database server ──────────────────
        case 'leaderboard_data':
            UI.renderLeaderboardMini(payload.data);
            break;

        // ── Pesan sistem (disconnect, dll.) ───────────────────
        case 'SYSTEM':
            Chat.renderSystem(payload.message);
            break;

        default:
            console.warn("[WS] Tipe pesan tidak dikenal:", payload.type);
    }
};

socket.onclose = () => {
    console.log("[WS] Koneksi terputus.");
    if (els.serverStatus) els.serverStatus.textContent = "OFFLINE";
};

// ─── END WEBSOCKET EVENT LISTENERS ───────────────────────────────────────────

function getLevelData(totalXP) {
    let currentLevel = 1;
    let nextXP = 0;
    let currentLevelXP = 0;
    for (let i = 0; i < LEVEL_TABLE.length; i++) {
        if (totalXP >= LEVEL_TABLE[i][1]) {
            currentLevel = LEVEL_TABLE[i][0];
            currentLevelXP = LEVEL_TABLE[i][1];
            nextXP = (i + 1 < LEVEL_TABLE.length) ? LEVEL_TABLE[i + 1][1] : LEVEL_TABLE[i][1];
        } else break;
    }
    let progressXP = totalXP - currentLevelXP;
    let neededXP = nextXP - currentLevelXP;
    if (neededXP <= 0) neededXP = 1;
    return {
        level: currentLevel,
        currentXP: totalXP,
        levelXP: currentLevelXP,
        nextXP: nextXP,
        progressXP: progressXP,
        neededXP: neededXP,
        progressPercent: (progressXP / neededXP) * 100
    };
}

function initDOM() {
    if (document.getElementById('login-screen')) {
        els.login = document.getElementById('login-screen');
        els.lobby = document.getElementById('lobby-screen');
        els.roomWaiting = document.getElementById('room-waiting-screen');
        els.game = document.getElementById('game-screen');
        els.idInput = document.getElementById('login-id');
        els.passInput = document.getElementById('login-pass');
        els.loginError = document.getElementById('login-error');
        els.btnLogin = document.getElementById('btn-login');
        els.btnRegister = document.getElementById('btn-register');
        els.btnGuest = document.getElementById('btn-guest');
        els.displayUser = document.getElementById('display-username');
        els.displayLvl = document.getElementById('display-level');
        els.xpBarContainer = document.getElementById('xp-bar-container');
        els.xpFill = document.getElementById('xp-fill');
        els.avatar = document.getElementById('avatar-display');
        els.playerList = document.getElementById('player-list-container');
        els.chatBox = document.getElementById('chat-box');
        els.chatInput = document.getElementById('chat-input');
        els.serverStatus = document.getElementById('server-status');
        els.roundInd = document.getElementById('round-indicator');
        els.gameArea = document.getElementById('game-area');
        els.trashContainer = document.getElementById('trash-container');
        els.msgMain = document.getElementById('center-msg-main');
        els.msgSub = document.getElementById('center-msg-sub');
        els.statAvg = document.getElementById('stat-avg');
        els.statBest = document.getElementById('stat-best');
        els.statScore = document.getElementById('stat-score');
        els.comboDisplay = document.getElementById('combo-display');
        els.comboVal = document.getElementById('combo-val');
        els.resModal = document.getElementById('result-modal');
        els.resScore = document.getElementById('res-score');
        els.resAvg = document.getElementById('res-avg');
        els.resMode = document.getElementById('res-mode');
        els.resXP = document.getElementById('res-xp');
        els.profileModal = document.getElementById('profile-modal');
        els.iconGrid = document.getElementById('icon-grid');
        els.profileLevel = document.getElementById('profile-level');
        els.profileNextUnlock = document.getElementById('profile-next-unlock');
        els.leaderboardMini = document.getElementById('leaderboard-mini');
        els.lobbyStatAvg = document.getElementById('lobby-stat-avg');
        els.lobbyStatBest = document.getElementById('lobby-stat-best');
        els.lobbyStatWR = document.getElementById('lobby-stat-wr');
        els.lobbyStatMatches = document.getElementById('lobby-stat-matches');
        els.roomPlayerCount = document.getElementById('room-player-count');
        els.roomSpecCount = document.getElementById('room-spec-count');
        els.roomSlotsGrid = document.getElementById('room-slots-grid');
        els.roomCountWaiting = document.getElementById('room-count-waiting');
        els.specCountWaiting = document.getElementById('spec-count-waiting');
        els.spectatorList = document.getElementById('spectator-list');
        els.btnFindMatchWaiting = document.getElementById('btn-find-match-waiting');
        els.gameTimer = document.getElementById('game-timer');
    }
    if (document.getElementById('sum-sessions')) {
        els.sumSessions = document.getElementById('sum-sessions');
        els.sumAvg = document.getElementById('sum-avg');
        els.sumBest = document.getElementById('sum-best');
        els.histList = document.getElementById('history-list');
        els.chartTrend = document.getElementById('chart-trend');
    }
}

function showScreen(name) {
    if (!els[name]) return;
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    els[name].classList.add('active');

    // Manage game background video
    const gameScreen = document.getElementById('game-screen');
    let gameVideoBg = document.getElementById('game-bg-video');

    if (name === 'game') {
        if (!gameVideoBg) {
            gameVideoBg = document.createElement('video');
            gameVideoBg.id = 'game-bg-video';
            gameVideoBg.autoplay = true;
            gameVideoBg.muted = true;
            gameVideoBg.loop = true;
            gameVideoBg.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;z-index:0;opacity:0.25;pointer-events:none;';
            const source = document.createElement('source');
            source.src = 'asset/backgrounds.mp4';
            source.type = 'video/mp4';
            gameVideoBg.appendChild(source);
            gameScreen.insertBefore(gameVideoBg, gameScreen.firstChild);
        }
        gameVideoBg.play().catch(() => {});
    } else {
        if (gameVideoBg) gameVideoBg.pause();
    }
}

function getLocalStorageUsers() { return JSON.parse(localStorage.getItem('rduel_users') || '{}'); }
function saveLocalStorageUsers(data) { localStorage.setItem('rduel_users', JSON.stringify(data)); }
function getCurrentUser() { return JSON.parse(sessionStorage.getItem('rduel_current') || 'null'); }
function saveCurrentUser(user) { sessionStorage.setItem('rduel_current', JSON.stringify(user)); }
function clearCurrentUser() { sessionStorage.removeItem('rduel_current'); }

function findUser(identifier) {
    const users = getLocalStorageUsers();
    if (users[identifier]) return users[identifier];
    const foundKey = Object.keys(users).find(key => users[key].email === identifier);
    return foundKey ? users[foundKey] : null;
}

function updateUserInDB(user) {
    if (!user || user.type === 'guest') return;
    const users = getLocalStorageUsers();
    const key = Object.keys(users).find(k => users[k].username === user.username) || user.username;
    users[key] = user;
    saveLocalStorageUsers(users);
}

const Auth = {
    login: () => {
        const id = els.idInput.value.trim();
        const pass = els.passInput.value;
        if (!id || !pass) { els.loginError.textContent = "Mohon isi Username/Email dan Password!"; return; }
        const user = findUser(id);
        if (user && user.password === pass) {
            AppState.user = user;
            AppState.isGuest = false;
            AppState.isLoggedIn = true;
            saveCurrentUser(AppState.user);
            Auth.onSuccess();
        } else {
            els.loginError.textContent = "Akun tidak ditemukan atau password salah.";
        }
    },
    register: () => {
        const id = els.idInput.value.trim();
        const pass = els.passInput.value;
        if (!id || !pass) { els.loginError.textContent = "Mohon isi semua field!"; return; }
        const existing = findUser(id);
        if (existing) { els.loginError.textContent = "Username atau Email sudah terdaftar!"; return; }
        const isEmail = id.includes('@');
        const displayUsername = isEmail ? id.split('@')[0] : id;
        const newUser = {
            username: displayUsername,
            email: isEmail ? id : '',
            password: pass,
            level: 1,
            icon: 'fa-user',
            gamesPlayed: 0,
            totalXP: 0,
            bestTime: null
        };
        const users = getLocalStorageUsers();
        users[displayUsername] = newUser;
        saveLocalStorageUsers(users);
        AppState.user = newUser;
        AppState.isGuest = false;
        AppState.isLoggedIn = true;
        saveCurrentUser(AppState.user);
        Auth.onSuccess();
    },
    loginGuest: () => {
        const randomId = Math.floor(Math.random() * 10000);
        const guestUser = { username: `Guest_${randomId}`, type: 'guest', icon: 'fa-ghost' };
        AppState.user = guestUser;
        AppState.isGuest = true;
        AppState.isLoggedIn = true;
        saveCurrentUser(AppState.user);
        Auth.onSuccess();
    },
    onSuccess: () => {
        els.loginError.textContent = "";
        showScreen('lobby');
        UI.updateProfileUI();
        UI.initIconPicker();
        UI.loadLobbyStats();
        UI.updateRoomUI();
        Network.connect();
    },
    checkSession: () => {
        const user = getCurrentUser();
        if (user) {
            AppState.user = user;
            AppState.isLoggedIn = true;
            AppState.isGuest = (user.type === 'guest');
            showScreen('lobby');
            UI.updateProfileUI();
            UI.initIconPicker();
            UI.loadLobbyStats();
            UI.updateRoomUI();
            Network.connect();
        } else {
            showScreen('login');
        }
    },
    logout: () => {
        clearCurrentUser();
        location.reload();
    }
};

const UI = {
    updateProfileUI: () => {
        if (!AppState.user || !els.displayUser) return;
        els.displayUser.textContent = AppState.user.username;
        if (AppState.isGuest) {
            els.displayLvl.textContent = "Guest";
            els.displayLvl.style.color = "#888";
            if (els.xpBarContainer) els.xpBarContainer.style.display = "none";
        } else {
            if (els.xpBarContainer) els.xpBarContainer.style.display = "block";
            const totalXP = AppState.user.totalXP || 0;
            const data = getLevelData(totalXP);
            let lvlHtml = `Lv ${data.level} <span>${data.progressXP.toFixed(0)} / ${data.neededXP.toFixed(0)} XP</span>`;
            if (data.level >= 20) lvlHtml = `Lv ${data.level} <span>MAX</span>`;
            els.displayLvl.innerHTML = lvlHtml;
            els.xpFill.style.width = `${data.progressPercent}%`;
        }
        els.avatar.innerHTML = `<i class="fas ${AppState.user.icon}"></i>`;
    },
    toggleProfile: () => {
        if (els.profileModal) els.profileModal.style.display = els.profileModal.style.display === 'flex' ? 'none' : 'flex';
    },
    initIconPicker: () => {
        if (!els.iconGrid) return;
        if (AppState.isGuest) {
            els.iconGrid.innerHTML = '<div class="empty-state">Guest tidak punya profil.</div>';
            els.profileLevel.textContent = "Guest";
            els.profileNextUnlock.textContent = "Login untuk save progress.";
            return;
        }
        const totalXP = AppState.user.totalXP || 0;
        const currentData = getLevelData(totalXP);
        els.profileLevel.textContent = `Level ${currentData.level} (${currentData.currentXP} XP)`;
        const nextUnlock = Object.entries(ICON_UNLOCKS).find(([lvl]) => lvl > currentData.level);
        if (nextUnlock) els.profileNextUnlock.textContent = `Next: ${nextUnlock[1].name} at Lv.${nextUnlock[0]}`;
        else els.profileNextUnlock.textContent = "All Icons Unlocked!";
        els.iconGrid.innerHTML = '';
        Object.entries(ICON_UNLOCKS).sort((a, b) => a[0] - b[0]).forEach(([lvl, data]) => {
            const div = document.createElement('div');
            div.className = 'icon-option';
            const isUnlocked = currentData.level >= lvl;
            if (isUnlocked) {
                div.innerHTML = `<i class="fas ${data.icon}" style="font-size:1.3rem;"></i>`;
                if (AppState.user.icon === data.icon) {
                    div.style.background = "rgba(0, 191, 165, 0.15)";
                    div.style.boxShadow = "0 0 10px rgba(0,191,165,.3)";
                }
                div.onclick = () => {
                    AppState.user.icon = data.icon;
                    saveCurrentUser(AppState.user);
                    updateUserInDB(AppState.user);
                    UI.updateProfileUI();
                    UI.initIconPicker();
                };
            } else {
                div.innerHTML = `<i class="fas fa-lock" style="font-size:1rem; color:#aaa;"></i>`;
                div.style.opacity = "0.5";
                div.style.cursor = "not-allowed";
            }
            els.iconGrid.appendChild(div);
        });
    },
    showResultModal: () => {
        if (!els.resModal) return;
        if (els.resMode) els.resMode.textContent = AppState.isVsAI ? "VS AI - " + AppState.aiDifficulty.toUpperCase() : "Match Finished";
        if (els.resScore) els.resScore.textContent = AppState.currentScore;
        const avg = AppState.reactionTimes.length ? (AppState.reactionTimes.reduce((a, b) => a + b, 0) / AppState.reactionTimes.length).toFixed(0) : '---';
        if (els.resAvg) els.resAvg.textContent = avg + ' ms';
        els.resModal.classList.add('show');
        els.resModal.style.display = 'flex';
    },
    retryGame: () => {
        if (els.resModal) {
            els.resModal.classList.remove('show');
            els.resModal.style.display = 'none';
        }
        if (AppState.isVsAI) {
            Game.startVsAI(AppState.aiDifficulty);
        } else {
            Game.startMatch();
        }
    },
    loadLobbyStats: () => {
        const sessions = JSON.parse(localStorage.getItem('reactionDuel_sessions') || '[]');
        if (sessions.length > 0) {
            const lastSession = sessions[0];
            if (lastSession.players && lastSession.players[0]) {
                const p = lastSession.players[0];
                if (els.lobbyStatAvg) els.lobbyStatAvg.textContent = p.avgTime ? p.avgTime + 'ms' : '---';
                if (els.lobbyStatBest) els.lobbyStatBest.textContent = p.bestTime ? p.bestTime + 'ms' : '---';
            }
            if (els.lobbyStatMatches) els.lobbyStatMatches.textContent = sessions.length;
            let wins = 0;
            sessions.forEach(s => {
                if (s.players && s.players[0] && s.players[0].xp === 500) wins++;
            });
            if (els.lobbyStatWR) els.lobbyStatWR.textContent = sessions.length > 0 ? Math.round((wins / sessions.length) * 100) + '%' : '--';
        }
    },
    renderLeaderboardMini: (data) => {
        if (!els.leaderboardMini) return;
        if (!data || data.length === 0) {
            els.leaderboardMini.innerHTML = '<div class="empty-state">Belum ada data</div>';
            return;
        }
        els.leaderboardMini.innerHTML = data.slice(0, 5).map((r, i) => {
            const rankClass = i === 0 ? 'rank-1' : i === 1 ? 'rank-2' : i === 2 ? 'rank-3' : 'rank-other';
            const isMe = AppState.user && r.username === AppState.user.username;
            return `<div class="lb-row ${isMe ? 'lb-me' : ''}">
                <span class="lb-rank-num ${rankClass}">${i + 1}</span>
                <div class="lb-av"><i class="fas fa-user"></i></div>
                <span class="lb-name">${r.username}</span>
                <span class="lb-score">${r.score || 0}</span>
            </div>`;
        }).join('');
    },
    updateRoomUI: () => {
        if (!AppState.user) return;
        AppState.roomPlayers = [];
        AppState.roomSpectators = [];
        if (els.roomPlayerCount) els.roomPlayerCount.textContent = '0';
        if (els.roomSpecCount) els.roomSpecCount.textContent = '0';
    },
    renderRoomSlots: () => {
        if (!els.roomSlotsGrid) return;
        let html = '';
        for (let i = 0; i < AppState.maxPlayers; i++) {
            const player = AppState.roomPlayers[i];
            if (player) {
                const isMe = AppState.user && player.username === AppState.user.username;
                html += `
                <div class="slot-card ${isMe ? 'filled-me' : 'filled'}">
                    <div class="slot-avatar has-user"><i class="fas ${player.icon || 'fa-user'}"></i></div>
                    <div class="slot-info">
                        <div class="slot-name">${player.username}${player.isBot ? ' 🤖' : ''}</div>
                        <div class="slot-status" style="color:var(--green)">${isMe ? '⬡ Kamu' : (player.isBot ? 'Bot Siap' : 'Online')}</div>
                    </div>
                </div>`;
            } else {
                html += `
                <div class="slot-card">
                    <div class="slot-avatar"><i class="fas fa-user-slash"></i></div>
                    <div class="slot-info">
                        <div class="slot-name slot-empty-text">Slot Kosong</div>
                        <div class="slot-status">Menunggu pemain...</div>
                    </div>
                </div>`;
            }
        }
        els.roomSlotsGrid.innerHTML = html;

        if (els.roomCountWaiting) els.roomCountWaiting.textContent = AppState.roomPlayers.length;
        if (els.specCountWaiting) els.specCountWaiting.textContent = AppState.roomSpectators.length;
        if (els.roomPlayerCount) els.roomPlayerCount.textContent = AppState.roomPlayers.length;
        if (els.roomSpecCount) els.roomSpecCount.textContent = AppState.roomSpectators.length;

        if (els.spectatorList) {
            if (AppState.roomSpectators.length === 0) {
                els.spectatorList.innerHTML = '<div style="font-size:11px; color:var(--text3);">Belum ada penonton.</div>';
            } else {
                els.spectatorList.innerHTML = AppState.roomSpectators.map(s =>
                    `<div class="spec-item"><i class="fas fa-eye"></i> ${s.username}</div>`
                ).join('');
            }
        }

        const isSpectator = AppState.roomSpectators.find(s => s.username === AppState.user.username);
        const canStart = AppState.roomPlayers.length >= 2;

        if (els.btnFindMatchWaiting) {
            if (isSpectator) {
                els.btnFindMatchWaiting.disabled = true;
                els.btnFindMatchWaiting.textContent = '👁 SPECTATING';
                els.btnFindMatchWaiting.style.opacity = '0.6';
            } else if (canStart) {
                els.btnFindMatchWaiting.disabled = false;
                els.btnFindMatchWaiting.textContent = `▶ START GAME (${AppState.roomPlayers.length} Pemain)`;
                els.btnFindMatchWaiting.style.opacity = '1';
            } else {
                els.btnFindMatchWaiting.disabled = true;
                els.btnFindMatchWaiting.textContent = '⏳ MENUNGGU PEMAIN... (Min. 2)';
                els.btnFindMatchWaiting.style.opacity = '0.7';
            }
        }
    }
};

const Network = {
    connect: () => {
        Network.refreshLeaderboard();
        // Jika socket sudah terbuka (user login setelah koneksi established), kirim JOIN sekarang.
        // Jika belum terbuka, socket.onopen di atas yang akan mengirimnya.
        if (socket.readyState === WebSocket.OPEN && AppState.user) {
            socket.send(JSON.stringify({
                type: 'JOIN',
                username: AppState.user.username,
                icon: AppState.user.icon || 'fa-user',
                isGuest: AppState.isGuest
            }));
        }
    },
    refreshLeaderboard: () => {
        // Prioritaskan data server; fallback ke localStorage jika socket belum siap.
        if (socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify({ type: 'get_leaderboard' }));
        } else {
            const sessions = JSON.parse(localStorage.getItem('reactionDuel_sessions') || '[]');
            const playerMap = {};
            sessions.forEach(s => {
                (s.players || []).forEach(p => {
                    if (!playerMap[p.username]) playerMap[p.username] = { username: p.username, score: 0 };
                    playerMap[p.username].score += (parseInt(p.score) || 0);
                });
            });
            const lbData = Object.values(playerMap).sort((a, b) => b.score - a.score);
            UI.renderLeaderboardMini(lbData);
        }
    }
};

// ─── OVERLAY READY ROOM (injected, tidak ubah HTML asli) ────────────────────
function injectReadyOverlay() {
    if (document.getElementById('ready-overlay')) return;
    const overlay = document.createElement('div');
    overlay.id = 'ready-overlay';
    overlay.style.cssText = `
        display:none; position:absolute; inset:0; z-index:30;
        background:rgba(26,35,126,0.65); backdrop-filter:blur(8px);
        flex-direction:column; align-items:center; justify-content:center;
        gap:24px;
    `;
    overlay.innerHTML = `
        <div style="font-size:1.3rem;font-weight:900;color:#fff;letter-spacing:3px;text-shadow:0 2px 10px rgba(0,0,0,.4);">
            MENUNGGU PEMAIN READY...
        </div>
        
        <div id="ro-players-container" style="display:flex;gap:15px;justify-content:center;align-items:center;flex-wrap:wrap;max-width:90%;">
        </div>

        <button id="ro-ready-btn" onclick="Game.confirmReady()" style="
            padding:16px 48px; background:linear-gradient(135deg,#FFA000,#FF6D00);
            color:#000; font-size:1.1rem; font-weight:900; border:none;
            border-radius:14px; cursor:pointer; letter-spacing:2px;
            box-shadow:0 6px 25px rgba(255,160,0,.4);
            animation: pulseBtnRO 1.5s infinite;
        ">⚡ SAYA SIAP!</button>
    `;
    document.getElementById('game-screen').appendChild(overlay);
}

// ─── ROUND INTRO OVERLAY ─────────────────────────────────────────────────────
function showRoundIntro(roundNum, callback) {
    let overlay = document.getElementById('round-intro-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'round-intro-overlay';
        overlay.style.cssText = `
            display:none; position:absolute; inset:0; z-index:25;
            background:rgba(26,35,126,0.7); backdrop-filter:blur(4px);
            flex-direction:column; align-items:center; justify-content:center;
            pointer-events:none;
        `;
        document.getElementById('game-screen').appendChild(overlay);
    }

    const roundColors = ['#00BFA5', '#2196F3', '#FF6B35', '#7C4DFF', '#E53935'];
    const color = roundColors[(roundNum - 1) % roundColors.length];

    // Difficulty increases
    const diffLabel = roundNum <= 1 ? '🟢 EASY' : roundNum <= 2 ? '🟡 MEDIUM' : roundNum <= 3 ? '🟠 HARD' : roundNum <= 4 ? '🔴 HARDER' : '💀 EXTREME';

    overlay.innerHTML = `
        <div style="text-align:center;">
            <div style="font-size:clamp(3rem,10vw,6rem);font-weight:900;color:${color};
                text-shadow:0 0 40px ${color}88;
                animation:introZoom .4s cubic-bezier(.175,.885,.32,1.275);">
                ROUND ${roundNum}
            </div>
            <div style="font-size:1.1rem;color:rgba(255,255,255,.8);margin-top:8px;font-weight:700;letter-spacing:2px;">
                ${diffLabel}
            </div>
        </div>
        <style>
            @keyframes introZoom { from{transform:scale(0);opacity:0} to{transform:scale(1);opacity:1} }
        </style>
    `;
    overlay.style.display = 'flex';

    setTimeout(() => {
        overlay.style.transition = 'opacity .3s';
        overlay.style.opacity = '0';
        setTimeout(() => {
            overlay.style.display = 'none';
            overlay.style.opacity = '1';
            overlay.style.transition = '';
            if (callback) callback();
        }, 300);
    }, 1200);
}

// ─── COUNTDOWN BEFORE SPAWN ──────────────────────────────────────────────────
function showCountdown(from, callback) {
    let overlay = document.getElementById('countdown-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'countdown-overlay';
        overlay.style.cssText = `
            display:none; position:absolute; inset:0; z-index:24;
            flex-direction:column; align-items:center; justify-content:center;
            pointer-events:none;
        `;
        document.getElementById('game-screen').appendChild(overlay);
    }

    let count = from;
    function tick() {
        if (count <= 0) {
            overlay.style.display = 'none';
            if (callback) callback();
            return;
        }
        const color = count <= 1 ? '#E53935' : count === 2 ? '#FFA000' : '#43A047';
        overlay.innerHTML = `
            <div style="font-size:clamp(4rem,12vw,8rem);font-weight:900;color:${color};
                text-shadow:0 0 30px ${color}88;
                animation:countPop .5s cubic-bezier(.175,.885,.32,1.275);">
                ${count}
            </div>
            <style>@keyframes countPop{from{transform:scale(2);opacity:0}to{transform:scale(1);opacity:1}}</style>
        `;
        overlay.style.display = 'flex';
        count--;
        setTimeout(tick, 700);
    }
    tick();
}

const Game = {
    enterRoom: () => {
        showScreen('roomWaiting');

        // Player masuk sebagai player jika slot < maxPlayers
        AppState.roomPlayers = [];
        AppState.roomSpectators = [];

        if (AppState.roomPlayers.length < AppState.maxPlayers) {
            AppState.roomPlayers.push(AppState.user);
        } else {
            // Slot penuh → jadi spectator
            AppState.roomSpectators.push(AppState.user);
        }

        // Auto-add 1 bot agar bisa langsung test (bisa diremove jika ada multiplayer nyata)
        const dummyBot = { username: 'Bot_Alpha', icon: 'fa-robot', isBot: true };
        if (AppState.roomPlayers.length < AppState.maxPlayers) {
            AppState.roomPlayers.push(dummyBot);
        }

        UI.renderRoomSlots();
    },

    leaveRoom: () => {
        AppState.roomPlayers = [];
        AppState.roomSpectators = [];
        showScreen('lobby');
    },

    startMatch: () => {
        if (AppState.roomPlayers.length < 2) {
            alert("Minimal 2 pemain dibutuhkan untuk mulai!");
            return;
        }

        AppState.isVsAI = false;
        AppState.matchFound = false;
        AppState.isUserReady = false;
        AppState.isOpponentReady = false;

        showScreen('game');
        injectReadyOverlay();

        AppState.isGameActive = false;
        AppState.currentRound = 1;
        AppState.reactionTimes = [];
        AppState.currentScore = 0;
        AppState.combo = 0;

        if (els.gameArea) els.gameArea.className = 'state-wait';
        if (els.roundInd) els.roundInd.textContent = "MATCHMAKING";
        if (els.msgMain) els.msgMain.textContent = "";
        if (els.msgSub) els.msgSub.textContent = "";

        setTimeout(() => { Game.showReadyRoom(); }, 600);
    },

    startVsAI: (difficulty) => {
        AppState.isVsAI = true;
        AppState.aiDifficulty = difficulty;
        AppState.matchFound = true;
        AppState.isUserReady = false;
        AppState.isOpponentReady = true;

        showScreen('game');
        injectReadyOverlay();

        AppState.isGameActive = false;
        AppState.currentRound = 1;
        AppState.reactionTimes = [];
        AppState.currentScore = 0;
        AppState.combo = 0;

        if (els.gameArea) els.gameArea.className = 'state-wait';
        if (els.roundInd) els.roundInd.textContent = "VS AI";
        if (els.msgMain) els.msgMain.textContent = "";
        if (els.msgSub) els.msgSub.textContent = "";

        setTimeout(() => { Game.showReadyRoomAI(); }, 500);
    },

    showReadyRoom: () => {
        AppState.matchFound = true;
        const overlay = document.getElementById('ready-overlay');
        if (!overlay) return;

        const container = document.getElementById('ro-players-container');
        container.innerHTML = ''; // Kosongkan container terlebih dahulu

        // Looping untuk membuat kartu sebanyak jumlah pemain (2, 3, atau 4)
        AppState.roomPlayers.forEach((p, index) => {
            const isMe = p.username === AppState.user.username;
            const statusColor = p.isBot ? '#43A047' : '#ccc';
            const statusText = p.isBot ? 'READY ✓' : 'NOT READY';

            container.innerHTML += `
                <div id="ro-card-${index}" style="background:rgba(255,255,255,.12);border:3px solid #fff;border-radius:16px;padding:20px 20px;text-align:center;min-width:110px;">
                    <div style="font-size:2rem;margin-bottom:8px;">${p.isBot ? '🤖' : '👤'}</div>
                    <div style="font-size:12px;font-weight:800;color:#fff;overflow:hidden;text-overflow:ellipsis;max-width:80px;margin:0 auto;">${isMe ? 'Kamu' : p.username}</div>
                    <div id="ro-dot-${index}" style="width:16px;height:16px;border-radius:50%;background:${statusColor};margin:10px auto 4px;transition:.3s;"></div>
                    <div id="ro-text-${index}" style="font-size:10px;color:rgba(255,255,255,.7);font-weight:700;">${statusText}</div>
                </div>
            `;
        });

        const btn = document.getElementById('ro-ready-btn');
        
        // Cek apakah user adalah penonton
        const isSpectator = AppState.roomSpectators.some(s => s.username === AppState.user.username);

        if (isSpectator) {
            btn.style.display = 'none'; // Penonton tidak punya tombol ready
        } else {
            btn.style.display = 'block';
            btn.disabled = false;
            btn.textContent = '⚡ SAYA SIAP!';
            btn.style.opacity = '1';
        }

        overlay.style.display = 'flex';
    },

    showReadyRoomAI: () => {
        const overlay = document.getElementById('ready-overlay');
        if (!overlay) return;

        const container = document.getElementById('ro-players-container');
        container.innerHTML = '';

        const aiName = AppState.aiDifficulty === 'hard' ? 'Bot ⚡' : 'Bot 🐢';

        // Buat 2 Kartu (Kita dan AI)
        container.innerHTML += `
            <div style="background:rgba(255,255,255,.12);border:3px solid #fff;border-radius:16px;padding:20px 20px;text-align:center;min-width:120px;">
                <div style="font-size:2rem;margin-bottom:8px;">👤</div>
                <div style="font-size:12px;font-weight:800;color:#fff;">Kamu</div>
                <div id="ro-dot-${0}" style="width:16px;height:16px;border-radius:50%;background:#ccc;margin:10px auto 4px;transition:.3s;"></div>
                <div id="ro-text-${0}" style="font-size:10px;color:rgba(255,255,255,.7);font-weight:700;">NOT READY</div>
            </div>
            <div style="background:rgba(255,255,255,.12);border:3px solid #fff;border-radius:16px;padding:20px 20px;text-align:center;min-width:120px;">
                <div style="font-size:2rem;margin-bottom:8px;">🤖</div>
                <div style="font-size:12px;font-weight:800;color:#fff;">${aiName}</div>
                <div style="width:16px;height:16px;border-radius:50%;background:#43A047;box-shadow:0 0 10px #43A047;margin:10px auto 4px;"></div>
                <div style="font-size:10px;color:rgba(255,255,255,.7);font-weight:700;">READY ✓</div>
            </div>
        `;

        const btn = document.getElementById('ro-ready-btn');
        if (btn) { btn.style.display = 'block'; btn.disabled = false; btn.textContent = '⚡ SAYA SIAP!'; btn.style.opacity = '1'; }

        overlay.style.display = 'flex';
    },

    confirmReady: () => {
        if (AppState.isUserReady) return;
        AppState.isUserReady = true;

        // Cari index diri sendiri di dalam array dan ubah kotaknya jadi hijau
        const myIndex = AppState.roomPlayers.findIndex(p => p.username === AppState.user.username);
        if (myIndex !== -1) {
            const dotMe = document.getElementById(`ro-dot-${myIndex}`);
            const txtMe = document.getElementById(`ro-text-${myIndex}`);
            if (dotMe) { dotMe.style.background = '#43A047'; dotMe.style.boxShadow = '0 0 10px #43A047'; }
            if (txtMe) txtMe.textContent = 'READY ✓';
        }

        const btn = document.getElementById('ro-ready-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Menunggu Pemain Lain...'; btn.style.opacity = '0.6'; }

        if (AppState.isVsAI) {
            // VS AI: tetap lokal, langsung mulai setelah jeda singkat
            setTimeout(() => { Game._hideReadyAndStart(); }, 600);
        } else {
            // Multiplayer: beri tahu server bahwa pemain ini siap.
            // Server akan broadcast PLAYER_READY_UPDATE ke semua,
            // lalu mengirim START_GAME saat semua ready.
            socket.send(JSON.stringify({ type: 'PLAYER_READY' }));
        }
    },

    _hideReadyAndStart: () => {
        const overlay = document.getElementById('ready-overlay');
        if (overlay) overlay.style.display = 'none';
        AppState.isGameActive = true;
        Game.nextRound();
    },

    nextRound: () => {
        // Untuk multiplayer: alur ronde sepenuhnya dikontrol server via ROUND_UPDATE + SPAWN_ITEMS.
        // Fungsi ini hanya dijalankan untuk mode VS AI (lokal).
        if (!AppState.isVsAI) return;

        if (AppState.currentRound > AppState.maxRounds) { Game.endGame(); return; }
        if (els.comboDisplay) els.comboDisplay.classList.remove('show');
        Game.setStateWait('', '');
        if (els.roundInd) els.roundInd.textContent = `ROUND ${AppState.currentRound} / ${AppState.maxRounds}`;

        // Show round intro then countdown then spawn (VS AI local flow)
        showRoundIntro(AppState.currentRound, () => {
            showCountdown(3, () => {
                Game.spawnTrash();
            });
        });
    },

    setStateWait: (mainTxt, subTxt) => {
        AppState.isGameActive = false;
        if (els.gameArea) els.gameArea.className = 'state-wait';
        if (els.msgMain) { els.msgMain.textContent = mainTxt; els.msgMain.style.color = ''; }
        if (els.msgSub) { els.msgSub.textContent = subTxt; els.msgSub.style.color = ''; }
        if (els.trashContainer) els.trashContainer.innerHTML = '';
        AppState.gameIntervals.forEach(clearInterval);
        AppState.gameIntervals = [];
        if (AppState.roundTimer) clearInterval(AppState.roundTimer);
        if (els.gameTimer) { els.gameTimer.textContent = "--"; els.gameTimer.classList.remove('warning'); }
    },

    startRoundTimer: (duration) => {
        let timeLeft = Math.ceil(duration / 1000);
        if (els.gameTimer) {
            els.gameTimer.textContent = timeLeft;
            els.gameTimer.classList.remove('warning');
        }
        AppState.roundTimer = setInterval(() => {
            timeLeft--;
            if (els.gameTimer) {
                els.gameTimer.textContent = timeLeft;
                if (timeLeft <= 3) els.gameTimer.classList.add('warning');
            }
            if (timeLeft <= 0) clearInterval(AppState.roundTimer);
        }, 1000);
    },

    // ─── spawnTrash: HANYA untuk mode VS AI (lokal) ──────────────────────────
    // Untuk multiplayer, item di-spawn melalui handleSpawnItems() saat server
    // mengirim payload SPAWN_ITEMS. Client tidak boleh generate item sendiri.
    spawnTrash: () => {
        if (!AppState.isVsAI) return; // Guard: hanya boleh jalan di mode VS AI

        AppState.isGameActive = true;
        if (els.gameArea) els.gameArea.className = 'state-go';
        if (els.msgMain) { els.msgMain.textContent = ''; }
        if (els.msgSub) { els.msgSub.textContent = ''; }
        if (els.trashContainer) els.trashContainer.innerHTML = '';
        AppState.gameStartTimestamp = performance.now();

        const round = AppState.currentRound;

        // Items: lebih banyak tiap ronde
        let itemCount = 4 + (round * 2);
        if (itemCount > 16) itemCount = 16;

        // Timer: makin cepat tiap ronde
        let duration = 5500 - (round * 500);
        if (duration < 2000) duration = 2000;

        Game.startRoundTimer(duration);

        // Bomb chance: makin banyak tiap ronde
        let bombChance = 0.10 + (round * 0.04);
        if (bombChance > 0.35) bombChance = 0.35;

        for (let i = 0; i < itemCount; i++) {
            const typeRand = Math.random();
            let type = 'good';
            let itemDuration = duration + (Math.random() * 200 - 100);
            if (typeRand < bombChance) { type = 'bad'; itemDuration += 300; }
            else if (typeRand > 0.85) { type = 'bonus'; itemDuration = 1800 - (round * 100); if (itemDuration < 900) itemDuration = 900; }

            const top = Math.random() * 50 + 15;
            const left = Math.random() * 60 + 10;
            // ID lokal untuk VS AI — tidak dikirim ke server
            const localId = `local_${round}_${i}_${Date.now()}`;
            Game.createItem(type, top, left, itemDuration, round, localId);
        }

        // End-of-round auto-timeout
        const endTimer = setTimeout(() => {
            if (AppState.isGameActive) {
                AppState.currentRound++;
                Game.nextRound();
            }
        }, duration + 500);
        AppState.gameIntervals.push(endTimer);

        if (AppState.isVsAI || AppState.roomPlayers.some(p => p.isBot)) {
            Game.runAI();
        }
    },

    runAI: () => {
        const round = AppState.currentRound;
        const baseMsEasy = 700 - (round * 30);
        const baseMsHard = 300 - (round * 20);
        const baseTime = AppState.aiDifficulty === 'hard' ? Math.max(baseMsHard, 120) : Math.max(baseMsEasy, 350);
        const variance = AppState.aiDifficulty === 'hard' ? 80 : 180;

        setTimeout(() => {
            const items = document.querySelectorAll('.trash-item.good, .trash-item.bonus');
            items.forEach((item, index) => {
                const delay = Math.random() * variance + (index * 80);
                setTimeout(() => {
                    if (AppState.isGameActive && item.parentNode) {
                        if (item.parentNode) item.parentNode.removeChild(item);
                        Game.checkEndRound();
                    }
                }, delay);
            });
        }, baseTime);
    },

    // ─── createItem: mendukung ID dari server (multiplayer) ──────────────────
    // Parameter `id` bersifat opsional; VS AI menyuplai ID lokal,
    // server multiplayer menyuplai ID canonical (misal: "item_2_5").
    createItem: (type, top, left, duration, round, id = null) => {
        const itemId = id || `local_${type}_${Date.now()}_${Math.random().toString(36).substr(2, 5)}`;

        const el = document.createElement('div');
        el.className = `trash-item ${type}`;
        el.style.top = top + '%';
        el.style.left = left + '%';
        el.dataset.itemId = itemId; // ← Dipakai oleh processClick untuk kirim ke server

        let iconClass = 'fa-recycle icon-good';
        if (type === 'bad') iconClass = 'fa-bomb icon-bad';
        if (type === 'bonus') iconClass = 'fa-gem icon-bonus';
        el.innerHTML = `<i class="fas ${iconClass}"></i>`;

        // Store intervals on element itself to allow cleanup on click (fix memory leak)
        el._intervals = [];

        el.onpointerdown = (e) => { e.preventDefault(); e.stopPropagation(); Game.processClick(el, type); };

        // Movement speed per ronde:
        // Ronde 1-2 = diam
        // Ronde 3   = pelan (0.6)
        // Ronde 4   = sedang (1.3)
        // Ronde 5   = kencang (2.2)
        const speedMap = { 1: 0, 2: 0, 3: 0.2, 4: 0.1, 5: 0.2 };
        const moveSpeed = speedMap[round] !== undefined ? speedMap[round] : 2.2;

        let x = parseFloat(left);
        let y = parseFloat(top);
        let dx = 0, dy = 0;

        if (moveSpeed > 0) {
            const angle = Math.random() * Math.PI * 2;
            dx = Math.cos(angle) * moveSpeed;
            dy = Math.sin(angle) * moveSpeed;
        }

        const moveInt = setInterval(() => {
            if (!document.body.contains(el)) { clearInterval(moveInt); return; }
            if (moveSpeed === 0) return;
            x += dx;
            y += dy;
            if (x < 5 || x > 82) dx *= -1;
            if (y < 8 || y > 78) dy *= -1;
            el.style.left = x + '%';
            el.style.top = y + '%';
        }, 16);
        AppState.gameIntervals.push(moveInt);
        el._intervals.push(moveInt);

        // Despawn after duration (hanya relevan untuk VS AI; multiplayer pakai ITEM_EXPIRED dari server)
        const startTime = performance.now();
        const despawnInt = setInterval(() => {
            if (!AppState.isGameActive) { clearInterval(despawnInt); clearInterval(moveInt); return; }
            const elapsed = performance.now() - startTime;
            if (elapsed > duration) {
                clearInterval(despawnInt);
                clearInterval(moveInt);
                if (el.parentNode) el.parentNode.removeChild(el);
                if (type === 'good') {
                    AppState.combo = 0;
                    Game.updateStats();
                }
                // checkEndRound hanya relevan di VS AI; untuk multiplayer server yang memicu
                if (AppState.isVsAI) Game.checkEndRound();
            }
        }, 100);
        AppState.gameIntervals.push(despawnInt);
        el._intervals.push(despawnInt);

        if (els.trashContainer) els.trashContainer.appendChild(el);
    },

    // ─── processClick: REFAKTOR ───────────────────────────────────────────────
    // VS AI  → skor dihitung lokal (seperti sebelumnya).
    // Multiplayer → hanya kirim itemId ke server; server yang menghitung skor
    //               dan membroadcast SCORE_UPDATE kembali ke semua klien.
    processClick: (el, type) => {
        if (!AppState.isGameActive) return;

        // Hapus item dari DOM segera (responsivitas visual untuk semua mode)
        if (el._intervals) el._intervals.forEach(clearInterval);
        if (el.parentNode) el.parentNode.removeChild(el);

        if (AppState.isVsAI) {
            // ── Mode VS AI: kalkulasi skor lokal (tidak berubah) ──────────────
            const reactionTime = performance.now() - AppState.gameStartTimestamp;
            AppState.reactionTimes.push(reactionTime);

            if (type === 'bad') {
                Game.showFeedback("BOMB! -50", "#ff4444", false);
                AppState.currentScore -= 50;
                AppState.combo = 0;
            } else if (type === 'bonus') {
                Game.showFeedback("+250 BONUS!", "#ffd700", true);
                AppState.currentScore += 250;
                AppState.combo++;
            } else {
                const comboBonus = Math.min(AppState.combo, 10) * 10;
                const score = 100 + comboBonus;
                Game.showFeedback(`${Math.floor(reactionTime)}ms +${score}`, "#00ff88", true);
                AppState.currentScore += score;
                AppState.combo++;
            }
            Game.updateStats();
            Game.checkEndRound();
        } else {
            // ── Mode Multiplayer: delegasikan ke server ───────────────────────
            // Server membaca itemId, mencocokkan dengan activeItems[],
            // menghitung reaksi dari spawnedAt, memvalidasi anti-cheat,
            // lalu membroadcast SCORE_UPDATE ke semua pemain.
            const itemId = el.dataset.itemId || '';
            socket.send(JSON.stringify({
                type: 'ITEM_CLICKED',
                itemId: itemId
            }));

            // Tampilkan feedback visual optimistik (tidak tunggu server)
            // Jenis item sudah diketahui client dari class elemen
            if (type === 'bad') {
                Game.showFeedback("BOMB!", "#ff4444", false);
            } else if (type === 'bonus') {
                Game.showFeedback("BONUS!", "#ffd700", true);
            } else {
                Game.showFeedback("HIT!", "#00ff88", true);
            }
            // Skor & combo sesungguhnya akan diupdate saat SCORE_UPDATE diterima
        }
    },

    checkEndRound: () => {
        setTimeout(() => {
            if (!AppState.isGameActive) return;
            const remaining = document.querySelectorAll('.trash-item.good, .trash-item.bonus').length;
            if (remaining === 0) {
                AppState.currentRound++;
                Game.nextRound();
            }
        }, 60);
    },

    showFeedback: (text, color, isPositive) => {
        if (els.msgSub) { els.msgSub.textContent = text; els.msgSub.style.color = color; }
        if (els.msgMain) { els.msgMain.textContent = isPositive ? "HIT!" : "MISS!"; els.msgMain.style.color = color; }
        if (isPositive && els.comboVal && els.comboDisplay) {
            els.comboVal.textContent = AppState.combo;
            if (AppState.combo >= 2) els.comboDisplay.classList.add('show');
        } else if (els.comboDisplay) {
            els.comboDisplay.classList.remove('show');
        }
    },

    updateStats: () => {
        const avg = AppState.reactionTimes.length ? (AppState.reactionTimes.reduce((a, b) => a + b, 0) / AppState.reactionTimes.length).toFixed(0) : '---';
        const best = AppState.reactionTimes.length ? Math.min(...AppState.reactionTimes).toFixed(0) : '---';
        if (els.statAvg) els.statAvg.textContent = avg;
        if (els.statBest) els.statBest.textContent = best;
        if (els.statScore) els.statScore.textContent = AppState.currentScore;
    },

    // ─── Handler: SPAWN_ITEMS (dari server, mode multiplayer) ─────────────────
    handleSpawnItems: (payload) => {
        AppState.isGameActive = true;
        AppState.gameStartTimestamp = performance.now();
        if (els.gameArea) els.gameArea.className = 'state-go';
        if (els.msgMain) els.msgMain.textContent = '';
        if (els.msgSub) els.msgSub.textContent = '';
        if (els.trashContainer) els.trashContainer.innerHTML = '';

        const items = payload.items || [];
        const round = payload.round || AppState.currentRound;

        // Hitung durasi terpanjang untuk timer UI (pakai max dari semua item)
        const maxDuration = items.reduce((max, item) => Math.max(max, item.duration || 0), 0);
        if (maxDuration > 0) Game.startRoundTimer(maxDuration);

        // Render setiap item sesuai instruksi server — posisi & durasi sudah ditentukan server
        items.forEach(item => {
            Game.createItem(item.type, item.top, item.left, item.duration, round, item.id);
        });
    },

    // ─── Handler: SCORE_UPDATE (dari server, mode multiplayer) ───────────────
    handleScoreUpdate: (payload) => {
        AppState.currentScore = parseInt(payload.myScore) || 0;
        AppState.combo = payload.myCombo || 0;

        // Update tampilan skor langsung dari data server yang sudah tervalidasi
        if (els.statScore) els.statScore.textContent = AppState.currentScore;
        if (els.statAvg) els.statAvg.textContent = payload.myAvgReaction || '---';
        if (els.statBest) els.statBest.textContent = payload.myBestReaction || '---';

        // Update combo display
        if (AppState.combo >= 2 && els.comboVal && els.comboDisplay) {
            els.comboVal.textContent = AppState.combo;
            els.comboDisplay.classList.add('show');
        } else if (els.comboDisplay) {
            els.comboDisplay.classList.remove('show');
        }
    },

    // ─── Handler: ITEM_EXPIRED (dari server, mode multiplayer) ───────────────
    handleItemExpired: (payload) => {
        const itemId = payload.itemId;
        const resetCombo = payload.resetCombo;

        // Temukan elemen item di DOM berdasarkan data-item-id dan hapus
        const itemEl = els.trashContainer
            ? els.trashContainer.querySelector(`[data-item-id="${itemId}"]`)
            : null;

        if (itemEl) {
            if (itemEl._intervals) itemEl._intervals.forEach(clearInterval);
            if (itemEl.parentNode) itemEl.parentNode.removeChild(itemEl);
        }

        if (resetCombo) {
            AppState.combo = 0;
            if (els.comboDisplay) els.comboDisplay.classList.remove('show');
            if (els.statScore) els.statScore.textContent = AppState.currentScore;
        }
        // Akhir ronde dideteksi server via checkRoundEnd(); client menunggu ROUND_RESULT.
    },

    // ─── Handler: ROUND_UPDATE (dari server, mode multiplayer) ───────────────
    handleRoundUpdate: (payload) => {
        AppState.currentRound = payload.round;
        if (els.comboDisplay) els.comboDisplay.classList.remove('show');
        if (els.roundInd) els.roundInd.textContent = `ROUND ${payload.round} / ${AppState.maxRounds}`;
        // Tampilkan animasi intro ronde; server akan kirim SPAWN_ITEMS setelah delay-nya sendiri
        showRoundIntro(payload.round, null);
    },

    // ─── Handler: ROUND_RESULT (dari server, mode multiplayer) ───────────────
    handleRoundResult: (payload) => {
        // Tampilkan hasil sementara; server akan kirim ROUND_UPDATE atau GAME_OVER berikutnya
        const winner = payload.roundWinner || '';
        if (els.msgMain) { els.msgMain.textContent = 'ROUND END'; els.msgMain.style.color = '#FFA000'; }
        if (els.msgSub) { els.msgSub.textContent = winner ? `🏆 ${winner}` : ''; els.msgSub.style.color = '#FFA000'; }
    },

    // ─── Handler: GAME_OVER (dari server, mode multiplayer) ──────────────────
    handleGameOver: (payload) => {
        // Ambil statistik milik sendiri dari payload untuk ditampilkan di modal
        const myStats = (payload.stats || []).find(
            s => AppState.user && s.username === AppState.user.username
        );

        if (myStats) {
            AppState.currentScore = parseInt(myStats.score) || 0;
            // Rekonstruksi reactionTimes dari avgTime server untuk kalkulasi display
            if (myStats.avgTime != null) {
                AppState.reactionTimes = [parseFloat(myStats.avgTime)];
            }
        }

        Game.endGame(false);
    },

    endGame: (isDisconnect = false) => {
        AppState.isGameActive = false;
        if (els.gameArea) els.gameArea.className = 'state-wait';
        if (AppState.roundTimer) clearInterval(AppState.roundTimer);
        AppState.gameIntervals.forEach(clearInterval);
        AppState.gameIntervals = [];

        // Hide overlays
        const ro = document.getElementById('ready-overlay');
        if (ro) ro.style.display = 'none';
        const ri = document.getElementById('round-intro-overlay');
        if (ri) ri.style.display = 'none';
        const co = document.getElementById('countdown-overlay');
        if (co) co.style.display = 'none';

        if (els.msgMain) els.msgMain.textContent = isDisconnect ? "FORFEIT" : "FINISH!";
        if (els.msgSub) { els.msgSub.textContent = ''; els.msgSub.style.color = ''; }

        try {
            if (AppState.isGuest) {
                if (els.resXP) { els.resXP.textContent = "+0 XP"; els.resXP.style.color = "#888"; }
            } else if (!AppState.isGuest) {
                const oldLevel = getLevelData(AppState.user.totalXP || 0).level;
                AppState.user.gamesPlayed = (AppState.user.gamesPlayed || 0) + 1;
                if (AppState.reactionTimes.length > 0) {
                    const avg = AppState.reactionTimes.reduce((a, b) => a + b, 0) / AppState.reactionTimes.length;
                    if (!AppState.user.bestTime || avg < AppState.user.bestTime) AppState.user.bestTime = avg;
                }
                let isWin = AppState.isVsAI ? true : (AppState.currentScore > 300 && !isDisconnect);
                let xpEarned = isWin ? XP_REWARDS.WIN : XP_REWARDS.LOSE;
                AppState.user.totalXP = (AppState.user.totalXP || 0) + xpEarned;
                const newLevelData = getLevelData(AppState.user.totalXP);
                AppState.user.level = newLevelData.level;
                if (els.resXP) { els.resXP.textContent = `+${xpEarned} XP`; els.resXP.style.color = isWin ? "#43A047" : "#888"; }
                if (newLevelData.level > oldLevel && els.msgSub) {
                    els.msgSub.innerHTML = `🎉 LEVEL UP! → Lv.${newLevelData.level}`;
                    els.msgSub.style.color = "#FFA000";
                }
                UI.updateProfileUI();
                saveCurrentUser(AppState.user);
                updateUserInDB(AppState.user);
                Storage.saveSession([{
                    username: AppState.user.username,
                    score: AppState.currentScore,
                    avgTime: AppState.reactionTimes.length ? (AppState.reactionTimes.reduce((a, b) => a + b, 0) / AppState.reactionTimes.length).toFixed(0) : '---',
                    bestTime: AppState.reactionTimes.length ? Math.min(...AppState.reactionTimes).toFixed(0) : '---',
                    xp: xpEarned
                }], AppState.isVsAI ? 'VS AI' : 'Ranked');
            }
            UI.showResultModal();
        } catch (e) {
            console.error("endGame error:", e);
            UI.showResultModal();
        }
    },

    exitGame: () => {
        if (AppState.isGameActive) {
            if (confirm("Yakin ingin keluar? Progress round ini akan hilang!")) {
                Game.endGame(true);
            }
        } else {
            Game.backToLobby();
        }
    },

    backToLobby: () => {
        if (els.resModal) { els.resModal.classList.remove('show'); els.resModal.style.display = 'none'; }
        AppState.isGameActive = false;
        AppState.gameIntervals.forEach(clearInterval);
        AppState.gameIntervals = [];
        showScreen('lobby');
        UI.updateProfileUI();
        UI.loadLobbyStats();
        UI.updateRoomUI();
        Network.refreshLeaderboard();
    }
};

const Storage = {
    saveSession: (players, mode) => {
        const key = 'reactionDuel_sessions';
        const sessions = JSON.parse(localStorage.getItem(key) || '[]');
        sessions.unshift({
            timestamp: new Date().toISOString(),
            players: players,
            mode: mode
        });
        if (sessions.length > 50) sessions.pop();
        localStorage.setItem(key, JSON.stringify(sessions));
    }
};

const Chat = {
    handleKey: (e) => { if (e.key === 'Enter') Chat.send(); },

    // ─── send: REFAKTOR ───────────────────────────────────────────────────────
    // Tidak lagi me-render langsung ke DOM. Pesan dikirim ke server;
    // server membroadcast CHAT_MESSAGE, dan Chat.render() yang akan merender.
    send: () => {
        if (!els.chatInput) return;
        const msg = els.chatInput.value.trim();
        if (!msg) return;
        socket.send(JSON.stringify({ type: 'CHAT', message: msg }));
        els.chatInput.value = '';
    },

    // ─── render: dipanggil dari socket.onmessage saat CHAT_MESSAGE masuk ─────
    render: (payload) => {
        if (!els.chatBox) return;
        const isMe = AppState.user && payload.username === AppState.user.username;
        const div = document.createElement('div');
        div.className = 'chat-msg';
        div.innerHTML = `<span class="chat-msg-meta" style="color:${isMe ? '#00BFA5' : '#FFA000'};">${payload.username}${payload.time ? ' [' + payload.time + ']' : ''}:</span> ${payload.message}`;
        els.chatBox.appendChild(div);
        els.chatBox.scrollTop = els.chatBox.scrollHeight;
    },

    // ─── renderSystem: untuk pesan sistem dari server (disconnect, dll.) ─────
    renderSystem: (message) => {
        if (!els.chatBox || !message) return;
        const div = document.createElement('div');
        div.className = 'chat-msg';
        div.style.color = 'rgba(255,255,255,0.4)';
        div.style.fontStyle = 'italic';
        div.innerHTML = `⚙️ ${message}`;
        els.chatBox.appendChild(div);
        els.chatBox.scrollTop = els.chatBox.scrollHeight;
    }
};

const Dashboard = {
    init: () => {
        const sessions = JSON.parse(localStorage.getItem('reactionDuel_sessions') || '[]');
        if (els.sumSessions) els.sumSessions.textContent = sessions.length;
        const allTimes = sessions.flatMap(s => s.players.map(p => parseFloat(p.avgTime))).filter(Boolean);
        if (allTimes.length > 0) {
            if (els.sumAvg) els.sumAvg.innerHTML = (allTimes.reduce((a, b) => a + b, 0) / allTimes.length).toFixed(0) + "<span class='unit'>ms</span>";
            if (els.sumBest) els.sumBest.innerHTML = Math.min(...allTimes).toFixed(0) + "<span class='unit'>ms</span>";
        }
        if (els.histList) {
            if (sessions.length === 0) {
                els.histList.innerHTML = '<div class="empty-state">Belum ada riwayat permainan</div>';
            } else {
                els.histList.innerHTML = sessions.slice(0, 10).map(s => {
                    const winner = [...(s.players || [])].sort((a, b) => (b.score || 0) - (a.score || 0))[0];
                    return `<div class="history-item">
                        <div>
                            <div class="session-time">${new Date(s.timestamp).toLocaleString('id-ID')}</div>
                            <div class="session-xp">XP: <span style="color:var(--green)">${s.players[0].xp || 0}</span></div>
                        </div>
                        <div class="history-stats">
                            <div>Skor: <span>${winner ? winner.score : 0}</span></div>
                            <div class="winner-tag">${winner ? winner.username : '?'}</div>
                        </div>
                    </div>`;
                }).join('');
            }
        }
        if (window.Chart && els.chartTrend) {
            if (sessions.length === 0) {
                if (els.chartTrend.parentElement) els.chartTrend.parentElement.innerHTML = '<div class="empty-state">Belum ada data</div>';
            } else {
                new Chart(els.chartTrend.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: sessions.slice(0, 10).reverse().map((_, i) => i + 1),
                        datasets: [{
                            label: 'Avg Time (ms)',
                            data: sessions.slice(0, 10).reverse().map(s => s.players[0]?.avgTime || 0),
                            borderColor: '#00BFA5',
                            tension: 0.4,
                            fill: true,
                            backgroundColor: 'rgba(0,191,165,.1)'
                        }]
                    },
                    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: false } } }
                });
            }
        }
    }
};


// ── MOBILE RESPONSIVE CSS INJECTION ─────────────────────────────────────────
(function injectMobileCSS() {
    const style = document.createElement('style');
    style.textContent = `
        /* Mobile: sembunyikan sidebar, full width panels */
        @media (max-width: 768px) {
            .lobby-sidebar { display: none !important; }
            #lobby-screen { flex-direction: column !important; }
            .lobby-main { padding: 8px 8px 80px 8px !important; }
            .panels-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
            .panel-card { min-height: unset !important; }
            .topbar { padding: 0 10px !important; height: 54px !important; }
            .topbar-center { font-size: 13px !important; letter-spacing: 2px !important; }
            .user-name { font-size: 12px !important; }
            .level-text { font-size: 10px !important; }
            .xp-bar-bg { width: 80px !important; }
            .btn-topbar { font-size: 10px !important; padding: 6px 10px !important; }
            .server-status-badge { display: none !important; }

            /* Login mobile */
            .login-box { padding: 28px 20px !important; margin: 0 12px !important; }

            /* Room waiting mobile */
            .room-waiting-container { padding: 10px !important; }
            .slots-grid { grid-template-columns: 1fr 1fr !important; gap: 8px !important; }
            .rw-actions { flex-direction: column !important; }
            .btn-rw { padding: 12px !important; }

            /* Game screen mobile */
            #game-ui-top { padding: 8px 12px !important; }
            .timer-display { font-size: 18px !important; }
            .btn-exit { font-size: 10px !important; padding: 8px 12px !important; }
            #round-indicator { font-size: 11px !important; }
            .trash-item { width: 70px !important; height: 70px !important; }
            .icon-good, .icon-bad, .icon-bonus { font-size: 2rem !important; }
            #stats-panel { gap: 8px !important; width: 96% !important; }
            .stat-box { padding: 8px 10px !important; min-width: 70px !important; }
            .stat-val { font-size: 0.9rem !important; }

            /* Result modal mobile */
            .result-box { padding: 24px 16px !important; margin: 0 12px !important; }
        }
    `;
    document.head.appendChild(style);
})();

window.onbeforeunload = function () {
    if (AppState.isGameActive) return "Game sedang berlangsung. Yakin ingin keluar?";
};

document.addEventListener('DOMContentLoaded', () => {
    initDOM();
    if (els.btnLogin) els.btnLogin.onclick = Auth.login;
    if (els.btnRegister) els.btnRegister.onclick = Auth.register;
    if (els.btnGuest) els.btnGuest.onclick = Auth.loginGuest;
    if (document.getElementById('sum-sessions')) Dashboard.init();
    else if (document.getElementById('login-screen')) Auth.checkSession();
});