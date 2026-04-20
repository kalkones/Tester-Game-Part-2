const SERVER_URL = "wss://tester-game-part-2-production.up.railway.app";
let socket;

let AppState = {
    user:        null,
    isLoggedIn:  false,
    isGuest:     false,

    // Game state (diisi dari server, bukan dihitung client)
    isGameActive:    false,
    currentRound:    1,
    myScore:         0,   // ← dari server, bukan dihitung client
    opponentScore:   0,   // ← dari server
    myCombo:         0,   // ← dari server
    myAvgReaction:   '---',
    myBestReaction:  '---',
    roundStartTime:  0,
    activeItemEls: {}
};

// ── XP & Level System (tetap di client untuk UI) ──
const XP_REWARDS   = { WIN: 500, LOSE: 100 };
const LEVEL_TABLE  = [
    [1,0],[2,200],[3,600],[4,1100],[5,1700],[6,2500],[7,3500],
    [8,4700],[9,6200],[10,8000],[11,10100],[12,12500],[13,15200],
    [14,18200],[15,21500],[16,25100],[17,29000],[18,33200],[19,37700],[20,45000]
];
const ICON_UNLOCKS = {
    1:{icon:'fa-user',name:'Recruit'}, 2:{icon:'fa-robot',name:'Bot Fighter'},
    4:{icon:'fa-cat',name:'Swift Cat'}, 6:{icon:'fa-dragon',name:'Dragonborn'},
    8:{icon:'fa-skull',name:'Reaper'}, 10:{icon:'fa-hat-wizard',name:'Wizard'},
    15:{icon:'fa-fire',name:'Inferno'}, 20:{icon:'fa-crown',name:'Legend'}
};

function getLevelData(totalXP) {
    let currentLevel=1, nextXP=0, currentLevelXP=0;
    for (let i=0; i<LEVEL_TABLE.length; i++) {
        if (totalXP >= LEVEL_TABLE[i][1]) {
            currentLevel=LEVEL_TABLE[i][0]; currentLevelXP=LEVEL_TABLE[i][1];
            nextXP = (i+1<LEVEL_TABLE.length) ? LEVEL_TABLE[i+1][1] : LEVEL_TABLE[i][1];
        } else break;
    }
    const progressXP = totalXP - currentLevelXP;
    const neededXP   = Math.max(1, nextXP - currentLevelXP);
    return { level:currentLevel, currentXP:totalXP, progressXP, neededXP,
             progressPercent:(progressXP/neededXP)*100 };
}

// ── DOM Elements ──────────────────────────────────────────────
const els = {};
function initDOM() {
    if (!document.getElementById('login-screen')) return;
    const ids = [
        'login-screen','lobby-screen','game-screen',
        'login-id','login-pass','login-error',
        'btn-login','btn-register','btn-guest',
        'display-username','display-level','xp-bar-container','xp-fill','avatar-display',
        'player-list-container','start-btn','lobby-status',
        'chat-box','chat-input','server-status',
        'round-indicator','mode-indicator','game-area','trash-container',
        'center-msg-main','center-msg-sub',
        'stat-avg','stat-best','stat-score',
        'combo-display','combo-val',
        'ready-room','btn-ready-confirm','opponent-name',
        'ready-status-me','ready-text-me','ready-status-opp','ready-text-opp',
        'result-modal','res-score','res-avg','res-best','res-mode','res-xp',
        'profile-modal','icon-grid','profile-level','profile-next-unlock'
    ];
    const keyMap = {
        'login-screen':'login','lobby-screen':'lobby','game-screen':'game',
        'login-id':'idInput','login-pass':'passInput','login-error':'loginError',
        'btn-login':'btnLogin','btn-register':'btnRegister','btn-guest':'btnGuest',
        'display-username':'displayUser','display-level':'displayLvl',
        'xp-bar-container':'xpBarContainer','xp-fill':'xpFill','avatar-display':'avatar',
        'player-list-container':'playerList','start-btn':'startBtn','lobby-status':'lobbyStatus',
        'chat-box':'chatBox','chat-input':'chatInput','server-status':'serverStatus',
        'round-indicator':'roundInd','mode-indicator':'modeInd',
        'game-area':'gameArea','trash-container':'trashContainer',
        'center-msg-main':'msgMain','center-msg-sub':'msgSub',
        'stat-avg':'statAvg','stat-best':'statBest','stat-score':'statScore',
        'combo-display':'comboDisplay','combo-val':'comboVal',
        'ready-room':'readyRoom','btn-ready-confirm':'btnReadyConfirm',
        'opponent-name':'opponentName','ready-status-me':'readyStatusMe',
        'ready-text-me':'readyTextMe','ready-status-opp':'readyStatusOpp',
        'ready-text-opp':'readyTextOpp','result-modal':'resModal',
        'res-score':'resScore','res-avg':'resAvg','res-best':'resBest',
        'res-mode':'resMode','res-xp':'resXP','profile-modal':'profileModal',
        'icon-grid':'iconGrid','profile-level':'profileLevel',
        'profile-next-unlock':'profileNextUnlock'
    };
    ids.forEach(id => {
        const key = keyMap[id] || id;
        els[key] = document.getElementById(id);
    });
}

function showScreen(name) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    if (els[name]) els[name].classList.add('active');
}

// ============================================================
//  AUTH — Gunakan WebSocket ke server
// ============================================================
const Auth = {
    login: () => {
        const id   = els.idInput?.value.trim();
        const pass = els.passInput?.value;
        if (!id || !pass) { if(els.loginError) els.loginError.textContent = "Isi username/email dan password!"; return; }
        if (!socket || socket.readyState !== WebSocket.OPEN) {
            Network.connect(() => Auth.sendLogin(id, pass));
        } else {
            Auth.sendLogin(id, pass);
        }
    },
    sendLogin: (id, pass) => {
        Network.send({ type: 'AUTH_LOGIN', identifier: id, password: pass });
        if(els.btnLogin) { els.btnLogin.textContent = "Masuk..."; els.btnLogin.disabled = true; }
    },
    register: () => {
        const id   = els.idInput?.value.trim();
        const pass = els.passInput?.value;
        if (!id || !pass) { if(els.loginError) els.loginError.textContent = "Isi semua field!"; return; }
        const isEmail  = id.includes('@');
        const username = isEmail ? id.split('@')[0] : id;
        const email    = isEmail ? id : '';
        if (!socket || socket.readyState !== WebSocket.OPEN) {
            Network.connect(() => Network.send({ type: 'AUTH_REGISTER', username, email, password: pass }));
        } else {
            Network.send({ type: 'AUTH_REGISTER', username, email, password: pass });
        }
        if(els.btnRegister) { els.btnRegister.textContent = "Daftar..."; els.btnRegister.disabled = true; }
    },
    loginGuest: () => {
        const randomId  = Math.floor(Math.random() * 10000);
        const guestUser = { username: `Guest_${randomId}`, type: 'guest', icon: 'fa-ghost', level: 1, totalXP: 0 };
        AppState.user    = guestUser;
        AppState.isGuest = true;
        AppState.isLoggedIn = true;
        sessionStorage.setItem('rduel_current', JSON.stringify(guestUser));
        Auth.onSuccess();
        Network.connect();
    },
    onSuccess: () => {
        if(els.loginError) els.loginError.textContent = '';
        showScreen('lobby');
        UI.updateProfileUI();
        UI.initIconPicker();
    },
    checkSession: () => {
        const saved = sessionStorage.getItem('rduel_current');
        if (saved) {
            AppState.user = JSON.parse(saved);
            AppState.isLoggedIn = true;
            AppState.isGuest    = (AppState.user.type === 'guest');
            Auth.onSuccess();
            Network.connect();
        } else {
            showScreen('login');
        }
    },
    logout: () => {
        sessionStorage.removeItem('rduel_current');
        if (socket) socket.close();
        location.reload();
    }
};

// ============================================================
//  NETWORK — WebSocket handler
// ============================================================
const Network = {
    connect: (onOpenCallback = null) => {
        if (socket && socket.readyState === WebSocket.OPEN) {
            if (onOpenCallback) onOpenCallback();
            return;
        }
        socket = new WebSocket(SERVER_URL);

        socket.onopen = () => {
            console.log("[WS] Terhubung ke server!");
            if(els.serverStatus) els.serverStatus.textContent = "ONLINE";
            if (AppState.user) {
                Network.send({
                    type:    'JOIN',
                    username: AppState.user.username,
                    icon:    AppState.user.icon || 'fa-user',
                    isGuest: AppState.isGuest
                });
            }
            if (onOpenCallback) onOpenCallback();
        };

        socket.onmessage = (event) => {
            try { Network.handleMessage(JSON.parse(event.data)); }
            catch (e) { console.error("[WS] Parse error:", e); }
        };

        socket.onerror = () => {
            if(els.serverStatus) els.serverStatus.textContent = "ERROR";
            console.error("[WS] Koneksi error");
        };

        socket.onclose = () => {
            if(els.serverStatus) els.serverStatus.textContent = "OFFLINE";
            console.log("[WS] Koneksi ditutup");
        };
    },

    send: (data) => {
        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify(data));
        } else {
            console.warn("[WS] Tidak bisa kirim, socket belum siap:", data.type);
        }
    },

    // ── Router pesan dari server ──────────────────────────────
    handleMessage: (msg) => {
        switch (msg.type) {

            // Auth
            // Auth
            case 'AUTH_RESULT':
                if (msg.success) {
                    AppState.user = msg.user;
                    AppState.isGuest = false;
                    AppState.isLoggedIn = true;
                    sessionStorage.setItem('rduel_current', JSON.stringify(msg.user));
                    Auth.onSuccess();
                    Network.send({
                        type:    'JOIN',
                        username: AppState.user.username,
                        icon:    AppState.user.icon || 'fa-user',
                        isGuest: false
                    });
                } else {
                    if(els.loginError) els.loginError.textContent = msg.message;
                    if(els.btnLogin)   { els.btnLogin.textContent = "LOG IN"; els.btnLogin.disabled = false; }
                }
                break;

            case 'REGISTER_RESULT':
                if (msg.success) {
                    AppState.user = msg.user;
                    AppState.isGuest = false;
                    AppState.isLoggedIn = true;
                    sessionStorage.setItem('rduel_current', JSON.stringify(msg.user));
                    Auth.onSuccess();
                    Network.send({
                        type:    'JOIN',
                        username: AppState.user.username,
                        icon:    AppState.user.icon || 'fa-user',
                        isGuest: false
                    });
                    
                } else {
                    if(els.loginError) els.loginError.textContent = msg.message;
                    if(els.btnRegister) { els.btnRegister.textContent = "CREATE NEW ACCOUNT"; els.btnRegister.disabled = false; }
                }
                break;

            // Lobby
            case 'PLAYER_LIST':
                UI.renderPlayerList(msg.players);
                break;

            case 'CHAT_MESSAGE':
                UI.appendChat(msg.username, msg.message);
                break;

            // Matchmaking
            case 'MATCH_SEARCHING':
                if(els.lobbyStatus) els.lobbyStatus.textContent = "Mencari lawan...";
                break;

            case 'MATCH_FOUND':
                Game.showReadyRoom(msg.opponent, msg.opponentIcon);
                break;

            case 'PLAYER_READY_UPDATE':
                if (msg.username !== AppState.user?.username) {
                    if(els.readyStatusOpp) els.readyStatusOpp.className = 'status-dot ready';
                    if(els.readyTextOpp)   els.readyTextOpp.textContent = "READY!";
                }
                break;

            // Game flow
            case 'START_GAME':
                Game.onStartGame();
                break;

            case 'ROUND_UPDATE':
                AppState.currentRound = msg.round;
                if(els.roundInd) els.roundInd.textContent = `MATCH ${msg.round} / 5`;
                break;

            // FIX BUG: Handle pesan WAIT dari server
            case 'WAIT':
                AppState.isGameActive = false; // item belum spawn, jangan terima klik
                if(els.gameArea)  els.gameArea.className  = 'state-wait';
                if(els.msgMain)   els.msgMain.textContent = `ROUND ${AppState.currentRound}`;
                if(els.msgSub)    els.msgSub.textContent  = "Get Ready...";
                if(els.msgMain)   els.msgMain.style.color = 'var(--accent-cyan)';
                break;

            // ── SPAWN_ITEMS: server kirim daftar item ──────────
            case 'SPAWN_ITEMS':
                Game.renderItems(msg.items, msg.round);
                break;

            // ── ITEM_EXPIRED: server kasih tau item sudah mati ─
            case 'ITEM_EXPIRED':
                Game.removeItemEl(msg.itemId);
                if (msg.resetCombo) {
                    AppState.myCombo = 0;
                    if(els.comboDisplay) els.comboDisplay.classList.remove('show');
                    Game.showFeedback("MISS!", "white", false);
                }
                break;

            // ── SCORE_UPDATE: skor resmi dari server ───────────
            case 'SCORE_UPDATE':
                Game.onScoreUpdate(msg);
                break;

            // ── ROUND_RESULT: hasil akhir ronde ────────────────
            case 'ROUND_RESULT':
                Game.onRoundResult(msg);
                break;

            // Game Over
            case 'GAME_OVER':
                Game.onGameOver(msg.stats);
                break;

            // Leaderboard
            case 'leaderboard_data':
                UI.renderLeaderboard(msg.data);
                break;

            case 'SYSTEM':
                console.log("[SYSTEM]", msg.message);
                if(els.lobbyStatus) els.lobbyStatus.textContent = msg.message;
                break;

            case 'ERROR':
                console.warn("[SERVER ERROR]", msg.message);
                if(els.loginError) els.loginError.textContent = msg.message;
                break;

            default:
                console.log("[WS] Unhandled:", msg.type);
        }
    }
};

// ============================================================
//  UI — Rendering only, NO game logic
// ============================================================
const UI = {
    updateProfileUI: () => {
        if (!AppState.user) return;
        if(els.displayUser) els.displayUser.textContent = AppState.user.username;
        if(els.avatar)      els.avatar.innerHTML = `<i class="fas ${AppState.user.icon || 'fa-user'}"></i>`;

        if (AppState.isGuest) {
            if(els.displayLvl) { els.displayLvl.textContent = "Guest"; els.displayLvl.style.color = "#888"; }
            if(els.xpBarContainer) els.xpBarContainer.style.display = "none";
        } else {
            if(els.xpBarContainer) els.xpBarContainer.style.display = "block";
            const d = getLevelData(AppState.user.totalXP || 0);
            if(els.displayLvl) els.displayLvl.innerHTML = `Lvl ${d.level} <span>${d.progressXP.toFixed(0)} / ${d.neededXP.toFixed(0)} XP</span>`;
            if(els.xpFill)     els.xpFill.style.width = `${d.progressPercent}%`;
        }
    },

    renderPlayerList: (players) => {
        if (!els.playerList) return;
        els.playerList.innerHTML = players.map(p =>
            `<div style="padding:10px 0; border-bottom:1px solid rgba(255,255,255,0.1); display:flex; justify-content:space-between; align-items:center;">
                <span><i class="fas ${p.icon||'fa-user'}" style="margin-right:8px; color:var(--accent-cyan)"></i>${p.name}</span>
                <span style="color:var(--accent-yellow); font-size:0.85rem;">${p.isGuest ? 'Guest' : `Lv.${p.level||1}`}</span>
             </div>`
        ).join('');
    },

    appendChat: (username, message) => {
        if (!els.chatBox) return;
        const div = document.createElement('div');
        div.className = 'chat-msg';
        div.innerHTML = `<strong>${username}:</strong> ${message} <span class="time">${time}</span>`;
        els.chatBox.appendChild(div);
        els.chatBox.scrollTop = els.chatBox.scrollHeight;
    },

    renderLeaderboard: (rows) => {
        console.log('[LB]', rows);
    },

    toggleProfile: () => {
        if (els.profileModal) els.profileModal.style.display =
            els.profileModal.style.display === 'flex' ? 'none' : 'flex';
    },

    initIconPicker: () => {
        if (!els.iconGrid) return;
        if (AppState.isGuest) {
            els.iconGrid.innerHTML = '<p style="color:#666; grid-column:1/-1; text-align:center;">Guest tidak punya profil.</p>';
            return;
        }
        const d = getLevelData(AppState.user.totalXP || 0);
        if(els.profileLevel)    els.profileLevel.textContent    = `Level ${d.level} (${d.currentXP} XP)`;
        const next = Object.entries(ICON_UNLOCKS).find(([lvl]) => lvl > d.level);
        if(els.profileNextUnlock) els.profileNextUnlock.textContent = next ? `Next: ${next[1].name} at Lv.${next[0]}` : 'All Unlocked!';

        els.iconGrid.innerHTML = '';
        Object.entries(ICON_UNLOCKS).sort((a,b)=>a[0]-b[0]).forEach(([lvl, data]) => {
            const div = document.createElement('div');
            div.className = 'icon-option';
            const unlocked = d.level >= lvl;
            if (unlocked) {
                div.innerHTML = `<i class="fas ${data.icon}" style="font-size:1.5rem;"></i>`;
                if (AppState.user.icon === data.icon) {
                    div.style.background  = "rgba(0,245,255,0.2)";
                    div.style.boxShadow   = "0 0 10px var(--accent-cyan)";
                }
                div.onclick = () => {
                    AppState.user.icon = data.icon;
                    sessionStorage.setItem('rduel_current', JSON.stringify(AppState.user));
                    Network.send({ type: 'UPDATE_ICON', username: AppState.user.username, icon: data.icon });
                    UI.updateProfileUI();
                    UI.initIconPicker();
                };
            } else {
                div.style.opacity = "0.4"; div.style.cursor = "not-allowed"; div.style.position = "relative";
                div.innerHTML = `<i class="fas fa-lock" style="font-size:1.2rem; color:#555;"></i><span style="position:absolute;bottom:2px;right:2px;font-size:0.6rem;color:#888;">Lv${lvl}</span>`;
            }
            els.iconGrid.appendChild(div);
        });
    },

    showResultModal: (stats) => {
        if (!els.resModal) return;
        const me = stats?.find(p => p.username === AppState.user?.username);
        if(els.resScore) els.resScore.textContent = me?.score    ?? AppState.myScore;
        if(els.resAvg)   els.resAvg.textContent   = me?.avgTime  ? `${me.avgTime}ms` : (AppState.myAvgReaction || '---');
        if(els.resBest)  els.resBest.textContent  = me?.bestTime ? `${me.bestTime}ms` : (AppState.myBestReaction || '---');

        // Tentukan menang/kalah berdasarkan urutan stats (sudah di-sort desc oleh server)
        const isWin = stats?.[0]?.username === AppState.user?.username;
        if(els.resMode) {
            els.resMode.textContent = isWin ? "VICTORY! 🏆" : "DEFEAT";
            els.resMode.style.color = isWin ? "var(--accent-green)" : "var(--accent-pink)";
        }

        // XP
        if (!AppState.isGuest && me) {
            const xpGained  = isWin ? XP_REWARDS.WIN : XP_REWARDS.LOSE;
            const oldLevel  = getLevelData(AppState.user.totalXP || 0).level;
            AppState.user.totalXP = (AppState.user.totalXP || 0) + xpGained;
            const newLevel  = getLevelData(AppState.user.totalXP).level;
            AppState.user.level = newLevel;
            sessionStorage.setItem('rduel_current', JSON.stringify(AppState.user));
            if(els.resXP) {
                els.resXP.textContent = `+${xpGained} XP`;
                els.resXP.style.color = isWin ? "var(--accent-green)" : "#888";
            }
            UI.updateProfileUI();
            if (newLevel > oldLevel) {
                if(els.msgSub) { els.msgSub.innerHTML = `LEVEL UP! Lv.${newLevel}`; els.msgSub.style.color = "var(--accent-yellow)"; }
            }
        }

        els.resModal.classList.add('show');
        els.resModal.style.display = 'flex';
    },

    // FIX BUG: UI.retryGame tidak ada sebelumnya → tombol PLAY AGAIN crash
    retryGame: () => {
        // Tutup modal
        if(els.resModal) { els.resModal.classList.remove('show'); els.resModal.style.display = 'none'; }
        // Reset state lokal
        AppState.isGameActive   = false;
        AppState.myScore        = 0;
        AppState.opponentScore  = 0;
        AppState.myCombo        = 0;
        AppState.activeItemEls  = {};
        if(els.trashContainer) els.trashContainer.innerHTML = '';
        // Kembali ke lobby dulu, lalu langsung cari match baru
        showScreen('lobby');
        UI.updateProfileUI();
        UI.initIconPicker();
        // Cari lawan baru otomatis
        setTimeout(() => Game.startMatch(), 300);
    },

    triggerLevelUpAnimation: () => {
        if(els.displayLvl) {
            els.displayLvl.classList.add('level-up-anim');
            setTimeout(() => els.displayLvl.classList.remove('level-up-anim'), 600);
        }
    }
};

// ============================================================
//  GAME — Display & Input only
//  Semua scoring/logic ada di server
// ============================================================
const Game = {

    // ── Matchmaking ──────────────────────────────────────────
    startMatch: () => {
        showScreen('game');
        AppState.isGameActive   = false;
        AppState.myScore        = 0;
        AppState.opponentScore  = 0;
        AppState.myCombo        = 0;
        AppState.activeItemEls  = {};

        if(els.gameArea)  els.gameArea.className = 'state-wait';
        if(els.readyRoom) els.readyRoom.classList.remove('active');
        if(els.roundInd)  els.roundInd.textContent = "MATCHMAKING";
        if(els.modeInd)   els.modeInd.textContent  = AppState.isGuest ? "GUEST MATCH" : "RANKED MATCH";
        if(els.msgMain)   els.msgMain.textContent  = "SEARCHING";
        if(els.msgSub)    els.msgSub.textContent   = "Finding an opponent...";

        // Reset score display
        if(els.statScore) els.statScore.textContent = '0';
        if(els.statAvg)   els.statAvg.textContent   = '---';
        if(els.statBest)  els.statBest.textContent  = '---';
        if(els.comboDisplay) els.comboDisplay.classList.remove('show');

        Network.send({ type: 'FIND_MATCH', username: AppState.user?.username });
    },

    showReadyRoom: (opponentName, opponentIcon = 'fa-robot') => {
        if(els.opponentName)   els.opponentName.textContent   = opponentName;
        if(els.readyRoom)      els.readyRoom.classList.add('active');
        if(els.readyStatusMe)  els.readyStatusMe.className   = 'status-dot';
        if(els.readyTextMe)    els.readyTextMe.textContent   = "NOT READY";
        if(els.readyStatusOpp) els.readyStatusOpp.className  = 'status-dot';
        if(els.readyTextOpp)   els.readyTextOpp.textContent  = "WAITING...";
        if(els.btnReadyConfirm){ els.btnReadyConfirm.disabled = false; els.btnReadyConfirm.textContent = "I AM READY"; }
        if(els.msgMain)   els.msgMain.textContent = "MATCH FOUND!";
        if(els.msgSub)    els.msgSub.textContent  = `vs ${opponentName}`;
    },

    confirmReady: () => {
        if(els.readyStatusMe)  els.readyStatusMe.className  = 'status-dot waiting';
        if(els.readyTextMe)    els.readyTextMe.textContent  = "WAITING OPPONENT...";
        if(els.btnReadyConfirm){ els.btnReadyConfirm.disabled = true; els.btnReadyConfirm.textContent = "READY"; }
        Network.send({ type: 'PLAYER_READY', username: AppState.user?.username });
    },

    onStartGame: () => {
        if(els.readyRoom) els.readyRoom.classList.remove('active');
        AppState.isGameActive = true;
        if(els.gameArea)  els.gameArea.className  = 'state-wait';
        if(els.msgMain)   els.msgMain.textContent = "ROUND 1";
        if(els.msgSub)    els.msgSub.textContent  = "Get Ready...";
    },

    // ── RENDER ITEMS dari server ──────────────────────────────
    renderItems: (items, round) => {
        if (!els.trashContainer) return;
        AppState.isGameActive = true;
        AppState.roundStartTime = performance.now();
        if(els.gameArea) els.gameArea.className = 'state-go';
        if(els.msgMain)  els.msgMain.textContent = '';
        if(els.msgSub)   els.msgSub.textContent  = '';
        els.trashContainer.innerHTML = '';
        AppState.activeItemEls = {};

        items.forEach(item => {
            Game.createItemEl(item, round);
        });
    },

    createItemEl: (item, round) => {
        const el = document.createElement('div');
        el.className = `trash-item ${item.type}`;
        el.style.top  = item.top  + '%';
        el.style.left = item.left + '%';

        let iconClass = 'fa-recycle icon-good';
        let color     = 'var(--accent-green)';
        if (item.type === 'bad')   { iconClass = 'fa-bomb icon-bad';  color = 'var(--accent-red)'; }
        if (item.type === 'bonus') { iconClass = 'fa-gem icon-bonus'; color = 'var(--accent-yellow)'; }

        el.innerHTML = `<i class="fas ${iconClass}"></i><div class="trash-timer"><div class="timer-fill" style="color:${color}"></div></div>`;

        // Animasi gerak (visual )
        if (round >= 3) {
            const sf = (round - 2) * 0.5;
            el.style.setProperty('--dx', `${(Math.random()-0.5)*80*sf}px`);
            el.style.setProperty('--dy', `${(Math.random()-0.5)*40*sf}px`);
            el.classList.add('moving');
        }

        // ── CLICK HANDLER: kirim itemId ke server, jangan hitung skor ──
        el.onpointerdown = (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!AppState.isGameActive) return;

            // 1. Hapus item dari layar SEGERA (client-side prediction visual)
            Game.removeItemEl(item.id);

            // 2. Kirim aksi ke server — server yang validasi & hitung skor
            Network.send({
                type:      'ITEM_CLICKED',
                itemId:    item.id,
                username:  AppState.user?.username,
                clickTime: Date.now()
            });

            // 3. Feedback visual instan (BUKAN skor — hanya animasi)
            const localReact = performance.now() - AppState.roundStartTime;
            if (item.type === 'bad') {
                Game.showFeedback("BOMB!", "var(--accent-red)", false);
            } else if (item.type === 'bonus') {
                Game.showFeedback("BONUS!", "var(--accent-yellow)", true);
            } else {
                Game.showFeedback(`${Math.floor(localReact)}<span class='unit-ms'>ms</span>`, "var(--accent-green)", true);
            }
        };

        // Timer visual (animasi bar )
        const timerFill = el.querySelector('.timer-fill');
        const startTime = performance.now();
        const interval  = setInterval(() => {
            const pct = 100 - ((performance.now() - startTime) / item.duration * 100);
            if (timerFill) timerFill.style.transform = `scaleX(${Math.max(0, pct/100)})`;
            if (pct <= 0)  clearInterval(interval);
        }, 16);

        AppState.activeItemEls[item.id] = el;
        els.trashContainer.appendChild(el);
    },

    removeItemEl: (itemId) => {
        const el = AppState.activeItemEls[itemId];
        if (el && el.parentNode) {
            el.parentNode.removeChild(el);
        }
        delete AppState.activeItemEls[itemId];
    },

    // ── Terima skor resmi dari server ────────────────────────
    onScoreUpdate: (msg) => {
        AppState.myScore       = msg.myScore       ?? AppState.myScore;
        AppState.opponentScore = msg.opponentScore ?? AppState.opponentScore;
        AppState.myCombo       = msg.myCombo       ?? AppState.myCombo;
        if (msg.myAvgReaction)  AppState.myAvgReaction  = msg.myAvgReaction;
        if (msg.myBestReaction) AppState.myBestReaction = msg.myBestReaction;

        if(els.statScore) els.statScore.textContent = AppState.myScore;
        if(els.statAvg)   els.statAvg.textContent   = AppState.myAvgReaction;
        if(els.statBest)  els.statBest.textContent  = AppState.myBestReaction;
        if(els.comboVal)  els.comboVal.textContent  = AppState.myCombo;
        if (AppState.myCombo > 1) {
            if(els.comboDisplay) els.comboDisplay.classList.add('show');
        } else {
            if(els.comboDisplay) els.comboDisplay.classList.remove('show');
        }
    },

    onRoundResult: (msg) => {
        const didIWin = msg.roundWinner === AppState.user?.username;
        if(els.msgMain) {
            els.msgMain.textContent = didIWin ? "ROUND WIN!" : "ROUND LOST";
            els.msgMain.style.color = didIWin ? "var(--accent-green)" : "var(--accent-red)";
        }
        if(els.msgSub) {
            // Tampilkan skor kedua pemain
            const myScore  = msg.scores?.[AppState.user?.username] ?? 0;
            const entries  = Object.entries(msg.scores ?? {});
            const oppEntry = entries.find(([u]) => u !== AppState.user?.username);
            const oppScore = oppEntry ? oppEntry[1] : 0;
            els.msgSub.textContent = `You: ${myScore} — Opp: ${oppScore}`;
            els.msgSub.style.color = '#fff';
        }
    },

    onGameOver: (stats) => {
        AppState.isGameActive = false;
        if(els.gameArea) els.gameArea.className = 'state-wait';
        if(els.msgMain)  els.msgMain.textContent = "FINISH";
        if(els.trashContainer) els.trashContainer.innerHTML = '';
        AppState.activeItemEls = {};
        UI.showResultModal(stats);
    },

    showFeedback: (text, color, isPositive) => {
        if(els.msgSub)  { els.msgSub.innerHTML = text; els.msgSub.style.color = color; }
        if(els.msgMain) { els.msgMain.textContent = isPositive ? "HIT!" : "OUCH!"; els.msgMain.style.color = color; }
    },

    backToLobby: () => {
        if(els.resModal) { els.resModal.classList.remove('show'); els.resModal.style.display = 'none'; }
        AppState.isGameActive   = false;
        AppState.myScore        = 0;
        AppState.opponentScore  = 0;
        AppState.myCombo        = 0;
        AppState.activeItemEls  = {};
        if(els.trashContainer) els.trashContainer.innerHTML = '';
        showScreen('lobby');
        UI.updateProfileUI();
        UI.initIconPicker();
    }
};

// ============================================================
//  CHAT
// ============================================================
const Chat = {
    handleKey: (e) => {
        if (e.key !== 'Enter' || !els.chatInput) return;
        const msg = els.chatInput.value.trim();
        if (!msg) return;
        Network.send({ type: 'CHAT', username: AppState.user?.username, message: msg });
        els.chatInput.value = '';
    }
};

// ============================================================
//  INIT
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    initDOM();
    if(els.btnLogin)    els.btnLogin.onclick    = Auth.login;
    if(els.btnRegister) els.btnRegister.onclick = Auth.register;
    if(els.btnGuest)    els.btnGuest.onclick    = Auth.loginGuest;
    Auth.checkSession();
});