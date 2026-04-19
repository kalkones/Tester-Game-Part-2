const SERVER_URL = "wss://project-game-production-ea26.up.railway.app";
let socket;

let AppState = {
    user: null, 
    isLoggedIn: false,
    isGuest: false,
    isGameActive: false,
    currentRound: 1,
    maxRounds: 5,
    reactionTimes: [],
    currentScore: 0,
    combo: 0,
    roundResults: [],
    gameIntervals: []
};

const els = {};

// --- SISTEM XP & LEVEL ---
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

function getLevelData(totalXP) {
    let currentLevel = 1; let nextXP = 0; let currentLevelXP = 0;
    for (let i = 0; i < LEVEL_TABLE.length; i++) {
        if (totalXP >= LEVEL_TABLE[i][1]) {
            currentLevel = LEVEL_TABLE[i][0]; currentLevelXP = LEVEL_TABLE[i][1];
            nextXP = (i + 1 < LEVEL_TABLE.length) ? LEVEL_TABLE[i+1][1] : LEVEL_TABLE[i][1];
        } else break;
    }
    let progressXP = totalXP - currentLevelXP;
    let neededXP = nextXP - currentLevelXP;
    if (neededXP <= 0) neededXP = 1;
    return {
        level: currentLevel, currentXP: totalXP, levelXP: currentLevelXP,
        nextXP: nextXP, progressXP: progressXP, neededXP: neededXP,
        progressPercent: (progressXP / neededXP) * 100
    };
}

function initDOM() {
    if(document.getElementById('login-screen')) {
        els.login = document.getElementById('login-screen');
        els.lobby = document.getElementById('lobby-screen');
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
        els.startBtn = document.getElementById('start-btn');
        els.lobbyStatus = document.getElementById('lobby-status');
        els.chatBox = document.getElementById('chat-box');
        els.chatInput = document.getElementById('chat-input');
        els.serverStatus = document.getElementById('server-status');
        els.roundInd = document.getElementById('round-indicator');
        els.modeInd = document.getElementById('mode-indicator');
        els.gameArea = document.getElementById('game-area');
        els.trashContainer = document.getElementById('trash-container');
        els.msgMain = document.getElementById('center-msg-main');
        els.msgSub = document.getElementById('center-msg-sub');
        els.statAvg = document.getElementById('stat-avg');
        els.statBest = document.getElementById('stat-best');
        els.statScore = document.getElementById('stat-score');
        els.comboDisplay = document.getElementById('combo-display');
        els.comboVal = document.getElementById('combo-val');
        els.readyRoom = document.getElementById('ready-room');
        els.btnReadyConfirm = document.getElementById('btn-ready-confirm');
        els.opponentName = document.getElementById('opponent-name');
        els.readyStatusMe = document.getElementById('ready-status-me');
        els.readyTextMe = document.getElementById('ready-text-me');
        els.readyStatusOpp = document.getElementById('ready-status-opp');
        els.readyTextOpp = document.getElementById('ready-text-opp');
        els.resModal = document.getElementById('result-modal');
        els.resScore = document.getElementById('res-score');
        els.resAvg = document.getElementById('res-avg');
        els.resBest = document.getElementById('res-best');
        els.resMode = document.getElementById('res-mode');
        els.resXP = document.getElementById('res-xp');
        els.profileModal = document.getElementById('profile-modal');
        els.iconGrid = document.getElementById('icon-grid');
        els.profileLevel = document.getElementById('profile-level');
        els.profileNextUnlock = document.getElementById('profile-next-unlock');
    }
    if(document.getElementById('sum-sessions')) {
        els.sumSessions = document.getElementById('sum-sessions');
        els.sumAvg = document.getElementById('sum-avg');
        els.sumBest = document.getElementById('sum-best');
        els.histList = document.getElementById('history-list');
        els.chartTrend = document.getElementById('chart-trend');
    }
}

function showScreen(name) {
    if(!els[name]) return;
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    els[name].classList.add('active');
}

// --- MANAJEMEN DATA ---
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
            AppState.user = user; AppState.isGuest = false; AppState.isLoggedIn = true;
            saveCurrentUser(AppState.user);
            Auth.onSuccess();
        } else { els.loginError.textContent = "Akun tidak ditemukan atau password salah."; }
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
            username: displayUsername, email: isEmail ? id : '', password: pass, 
            level: 1, icon: 'fa-user', gamesPlayed: 0, totalXP: 0, bestTime: null 
        };
        const users = getLocalStorageUsers();
        users[displayUsername] = newUser; 
        saveLocalStorageUsers(users);
        AppState.user = newUser; AppState.isGuest = false; AppState.isLoggedIn = true;
        saveCurrentUser(AppState.user); 
        Auth.onSuccess();
    },
    loginGuest: () => {
        const randomId = Math.floor(Math.random() * 10000);
        const guestUser = { username: `Guest_${randomId}`, type: 'guest', icon: 'fa-ghost' };
        AppState.user = guestUser; AppState.isGuest = true; AppState.isLoggedIn = true;
        saveCurrentUser(AppState.user); 
        Auth.onSuccess();
    },
    onSuccess: () => {
        els.loginError.textContent = "";
        showScreen('lobby');
        UI.updateProfileUI();
        UI.initIconPicker(); 
        Network.connect();
    },
    checkSession: () => {
        const user = getCurrentUser();
        if (user) {
            AppState.user = user; AppState.isLoggedIn = true; AppState.isGuest = (user.type === 'guest');
            showScreen('lobby');
            UI.updateProfileUI();
            UI.initIconPicker(); 
            Network.connect();
        } else { showScreen('login'); }
    },
    logout: () => {
        clearCurrentUser(); if(socket) socket.close(); location.reload();
    }
};

const UI = {
    updateProfileUI: () => {
        if (!AppState.user || !els.displayUser) return;
        els.displayUser.textContent = AppState.user.username;
        if (AppState.isGuest) {
            els.displayLvl.textContent = "Offline Mode";
            els.displayLvl.style.color = "#888";
            if(els.xpBarContainer) els.xpBarContainer.style.display = "none";
        } else {
            if(els.xpBarContainer) els.xpBarContainer.style.display = "block";
            const totalXP = AppState.user.totalXP || 0;
            const data = getLevelData(totalXP);
            let lvlHtml = `Lvl ${data.level} <span>${data.progressXP.toFixed(0)} / ${data.neededXP.toFixed(0)} XP</span>`;
            if (data.level >= 20) lvlHtml = `Lvl ${data.level} <span>MAX</span>`;
            els.displayLvl.innerHTML = lvlHtml;
            els.xpFill.style.width = `${data.progressPercent}%`;
        }
        els.avatar.innerHTML = `<i class="fas ${AppState.user.icon}"></i>`;
    },
    
    triggerLevelUpAnimation: () => {
        const lvlText = els.displayLvl;
        lvlText.classList.add('level-up-anim');
        setTimeout(() => lvlText.classList.remove('level-up-anim'), 600);
    },

    toggleProfile: () => {
        if(els.profileModal) els.profileModal.style.display = els.profileModal.style.display === 'flex' ? 'none' : 'flex';
    },
    initIconPicker: () => {
        if(!els.iconGrid) return;
        if(AppState.isGuest) {
            els.iconGrid.innerHTML = '<p style="color:#666; grid-column:1/-1; text-align:center;">Guest tidak punya profil.</p>';
            els.profileLevel.textContent = "Guest";
            els.profileNextUnlock.textContent = "Login untuk save progress.";
            return;
        }
        const totalXP = AppState.user.totalXP || 0;
        const currentData = getLevelData(totalXP);
        els.profileLevel.textContent = `Level ${currentData.level} (${currentData.currentXP} XP)`;
        const nextUnlock = Object.entries(ICON_UNLOCKS).find(([lvl, data]) => lvl > currentData.level);
        if(nextUnlock) els.profileNextUnlock.textContent = `Next Unlock: ${nextUnlock[1].name} at Lv.${nextUnlock[0]}`;
        else els.profileNextUnlock.textContent = "All Icons Unlocked!";
        els.iconGrid.innerHTML = '';
        const sortedUnlocks = Object.entries(ICON_UNLOCKS).sort((a, b) => a[0] - b[0]);
        sortedUnlocks.forEach(([lvl, data]) => {
            const div = document.createElement('div');
            div.className = 'icon-option';
            const isUnlocked = currentData.level >= lvl;
            if (isUnlocked) {
                div.innerHTML = `<i class="fas ${data.icon}" style="font-size:1.5rem;"></i>`;
                div.title = `${data.name}`;
                if (AppState.user.icon === data.icon) { div.style.background = "rgba(0, 245, 255, 0.2)"; div.style.boxShadow = "0 0 10px var(--accent-cyan)"; }
                div.onclick = () => {
                    AppState.user.icon = data.icon;
                    saveCurrentUser(AppState.user);
                    updateUserInDB(AppState.user); 
                    UI.updateProfileUI();
                    UI.initIconPicker(); 
                };
            } else {
                div.innerHTML = `<i class="fas fa-lock" style="font-size:1.2rem; color:#555;"></i><span style="position:absolute; bottom:2px; right:2px; font-size:0.6rem; color:#888;">Lv${lvl}</span>`;
                div.style.opacity = "0.4"; div.style.cursor = "not-allowed"; div.style.position = "relative";
            }
            els.iconGrid.appendChild(div);
        });
    },
    showResultModal: () => {
        // Cek elemen ada atau tidak
        if(!els.resModal) { console.error("Elemen #result-modal tidak ditemukan di HTML!"); return; }
        
        // Update Konten
        if(els.resMode) els.resMode.textContent = "Match Finished";
        if(els.resScore) els.resScore.textContent = AppState.currentScore;
        
        const avg = AppState.reactionTimes.length ? (AppState.reactionTimes.reduce((a,b)=>a+b,0) / AppState.reactionTimes.length).toFixed(0) : '---';
        const best = AppState.reactionTimes.length ? Math.min(...AppState.reactionTimes).toFixed(0) : '---';
        
        if(els.resAvg) els.resAvg.textContent = avg;
        if(els.resBest) els.resBest.textContent = best;
        
        // FIX: Paksa Modal Muncul
        els.resModal.classList.add('show');
        els.resModal.style.display = 'flex'; // Paksa display flex
        els.resModal.style.opacity = '1';
        els.resModal.style.zIndex = '9999';
        
        console.log("Result Modal dipaksa muncul.");
    },
    retryGame: () => {
        if(els.resModal) {
            els.resModal.classList.remove('show');
            els.resModal.style.display = 'none';
        }
        Game.startMatch();
    }
};

const Network = {
    connect: () => {
        if(els.serverStatus) els.serverStatus.textContent = "ONLINE";
        setTimeout(() => {
            Network.handlePlayerList([
                {username: 'ProGamer99', score: 2500},
                {username: 'Speedy', score: 1800}
            ]);
        }, 1000);
    },
    handlePlayerList: (players) => {
        if(!els.playerList) return;
        els.playerList.innerHTML = players.map(p => 
            `<div class="player-list-item" style="padding:10px 0; border-bottom:1px solid rgba(255,255,255,0.1)">
                <span>${p.username}</span> <span style="color:var(--accent-yellow)">${p.score} pts</span>
             </div>`
        ).join('');
    }
};

const Game = {
    startMatch: () => {
        showScreen('game');
        AppState.isUserReady = false; AppState.isOpponentReady = false; AppState.isGameActive = false;
        AppState.currentRound = 1; AppState.reactionTimes = []; AppState.currentScore = 0; AppState.combo = 0;
        els.gameArea.className = 'state-wait';
        els.readyRoom.classList.remove('active');
        els.roundInd.textContent = "MATCHMAKING";
        els.modeInd.textContent = AppState.isGuest ? "GUEST MATCH" : "RANKED MATCH";
        els.msgMain.textContent = "SEARCHING";
        els.msgSub.textContent = "Finding an opponent...";
        setTimeout(() => { Game.showReadyRoom(); }, 2000);
    },
    showReadyRoom: () => {
        const names = ['Shadow', 'Viper', 'Cyber', 'Neon'];
        AppState.opponentName = names[Math.floor(Math.random() * names.length)];
        els.opponentName.textContent = AppState.opponentName;
        els.readyRoom.classList.add('active');
        els.gameArea.classList.add('state-wait'); 
        els.readyStatusMe.className = 'status-dot';
        els.readyTextMe.textContent = "NOT READY";
        els.readyStatusOpp.className = 'status-dot';
        els.readyTextOpp.textContent = "WAITING...";
        els.btnReadyConfirm.disabled = false;
        els.btnReadyConfirm.textContent = "I AM READY";
    },
    confirmReady: () => {
        if(AppState.isUserReady) return;
        AppState.isUserReady = true;
        els.readyStatusMe.className = 'status-dot waiting';
        els.readyTextMe.textContent = "WAITING OPPONENT...";
        els.btnReadyConfirm.disabled = true;
        els.btnReadyConfirm.textContent = "READY";
        setTimeout(() => {
            AppState.isOpponentReady = true;
            els.readyStatusOpp.className = 'status-dot waiting';
            els.readyTextOpp.textContent = "WAITING OPPONENT...";
            setTimeout(() => { Game.startActualGame(); }, 1000);
        }, Math.random() * 2000 + 1000);
    },
    startActualGame: () => {
        els.readyRoom.classList.remove('active');
        AppState.isGameActive = true;
        Game.nextRound();
    },
    nextRound: () => {
        if (AppState.currentRound > AppState.maxRounds) { Game.endGame(); return; }
        els.comboDisplay.classList.remove('show');
        Game.setStateWait(`ROUND ${AppState.currentRound}`, "Get Ready...");
        els.roundInd.textContent = `MATCH ${AppState.currentRound} / 5`;
        const delay = Math.random() * 2000 + 1000;
        setTimeout(() => { Game.spawnTrash(); }, delay);
    },
    setStateWait: (mainTxt = "WAIT", subTxt = "") => {
        AppState.isGameActive = false;
        els.gameArea.className = 'state-wait';
        els.msgMain.textContent = mainTxt; els.msgSub.textContent = subTxt;
        els.trashContainer.innerHTML = '';
        AppState.gameIntervals.forEach(clearInterval);
        AppState.gameIntervals = [];
    },
    spawnTrash: () => {
        AppState.isGameActive = true;
        els.gameArea.className = 'state-go';
        els.msgMain.textContent = ""; els.msgSub.textContent = "";
        els.trashContainer.innerHTML = '';
        AppState.gameStartTimestamp = performance.now();
        let itemCount = 5 + ((AppState.currentRound - 1) * 2); if(itemCount > 15) itemCount = 15; 
        let duration = 3200 - ((AppState.currentRound - 1) * 450); if(duration < 1300) duration = 1300;
        let bombChance = 0.20 + (AppState.currentRound * 0.05);
        for(let i=0; i<itemCount; i++) {
            const typeRand = Math.random();
            let type = 'good'; let itemDuration = duration + (Math.random() * 400 - 200);
            if(typeRand < bombChance) { type = 'bad'; itemDuration += 500; }
            else if(typeRand > 0.85) { type = 'bonus'; itemDuration = 1200; }
            const top = Math.random() * 60 + 15; const left = Math.random() * 70 + 10;
            Game.createItem(type, top, left, itemDuration, AppState.currentRound);
        }
    },
    createItem: (type, top, left, duration, round) => {
        const el = document.createElement('div');
        el.className = `trash-item ${type}`;
        el.style.top = top + '%'; el.style.left = left + '%';
        let iconClass = 'fa-recycle icon-good'; let color = 'var(--accent-green)';
        if(type === 'bad') { iconClass = 'fa-bomb icon-bad'; color = 'var(--accent-red)'; }
        if(type === 'bonus') { iconClass = 'fa-gem icon-bonus'; color = 'var(--accent-yellow)'; }
        el.innerHTML = `<i class="fas ${iconClass}"></i><div class="trash-timer"><div class="timer-fill" style="color:${color}"></div></div>`;
        if (round >= 3) {
            const speedFactor = (round - 2) * 0.5;
            const moveX = (Math.random() - 0.5) * 80 * speedFactor;
            const moveY = (Math.random() - 0.5) * 40 * speedFactor;
            el.style.setProperty('--dx', `${moveX}px`); el.style.setProperty('--dy', `${moveY}px`);
            el.classList.add('moving');
        }
        el.onpointerdown = (e) => { e.preventDefault(); e.stopPropagation(); Game.processClick(el, type); };
        const timerFill = el.querySelector('.timer-fill'); const startTime = performance.now();
        const interval = setInterval(() => {
            if(!AppState.isGameActive) { clearInterval(interval); return; }
            const elapsed = performance.now() - startTime;
            const pct = 100 - (elapsed / duration * 100);
            timerFill.style.transform = `scaleX(${Math.max(0, pct/100)})`;
            if(pct <= 0) {
                clearInterval(interval);
                if(el.parentNode) el.parentNode.removeChild(el);
                if(type === 'good') { Game.showFeedback("MISS!", "white", false); AppState.combo = 0; Game.updateStats(); }
                Game.checkEndRound();
            }
        }, 16);
        AppState.gameIntervals.push(interval);
        els.trashContainer.appendChild(el);
    },
    processClick: (el, type) => {
        if(!AppState.isGameActive) return;
        const reactionTime = performance.now() - AppState.gameStartTimestamp;
        AppState.reactionTimes.push(reactionTime);
        if(el.parentNode) el.parentNode.removeChild(el);
        if(type === 'bad') {
            Game.showFeedback("BOMB!", "var(--accent-red)", false);
            AppState.currentScore -= 50; AppState.combo = 0;
        } else if (type === 'bonus') {
            Game.showFeedback(`+250!`, "var(--accent-yellow)", true);
            AppState.currentScore += 250; AppState.combo++;
        } else {
            const score = 100 + (Math.min(AppState.combo, 10) * 10);
            Game.showFeedback(`${Math.floor(reactionTime)}<span class='unit-ms'>ms</span>`, "var(--accent-green)", true);
            AppState.currentScore += score; AppState.combo++;
        }
        Game.updateStats();
        Game.checkEndRound();
    },
    checkEndRound: () => {
        setTimeout(() => {
            if (!AppState.isGameActive) return;
            const remainingItems = document.querySelectorAll('.trash-item').length;
            if(remainingItems === 0) { AppState.currentRound++; Game.nextRound(); }
        }, 50);
    },
    showFeedback: (text, color, isPositive) => {
        els.msgSub.innerHTML = text; els.msgSub.style.color = color;
        els.msgMain.textContent = isPositive ? "HIT!" : "OUCH!"; els.msgMain.style.color = color;
        if(isPositive) { els.comboVal.textContent = AppState.combo; els.comboDisplay.classList.add('show'); } 
        else { els.comboDisplay.classList.remove('show'); }
    },
    updateStats: () => {
        const avg = AppState.reactionTimes.length ? (AppState.reactionTimes.reduce((a,b)=>a+b,0) / AppState.reactionTimes.length).toFixed(0) : '---';
        const best = AppState.reactionTimes.length ? Math.min(...AppState.reactionTimes).toFixed(0) : '---';
        els.statAvg.textContent = avg; els.statBest.textContent = best; els.statScore.textContent = AppState.currentScore;
    },
    endGame: () => {
        AppState.isGameActive = false;
        els.gameArea.className = 'state-wait';
        els.msgMain.textContent = "FINISH";
        
        try {
            if(AppState.isGuest) {
                els.resXP.textContent = "+0 XP";
                els.resXP.style.color = "#666";
            } else {
                const oldLevel = getLevelData(AppState.user.totalXP || 0).level;
                
                AppState.user.gamesPlayed++;
                if(AppState.reactionTimes.length > 0) {
                    const avg = AppState.reactionTimes.reduce((a,b)=>a+b,0) / AppState.reactionTimes.length;
                    if(!AppState.user.bestTime || avg < AppState.user.bestTime) AppState.user.bestTime = avg;
                }
                
                let isWin = AppState.currentScore > 300;
                let xpEarned = isWin ? XP_REWARDS.WIN : XP_REWARDS.LOSE;
                AppState.user.totalXP = (AppState.user.totalXP || 0) + xpEarned;
                
                const newLevelData = getLevelData(AppState.user.totalXP);
                AppState.user.level = newLevelData.level;

                els.resXP.textContent = `+${xpEarned} XP`;
                els.resXP.style.color = isWin ? "var(--accent-green)" : "#888"; 

                if(newLevelData.level > oldLevel) {
                    els.msgSub.innerHTML = `LEVEL UP! Lv.${newLevelData.level}`;
                    els.msgSub.style.color = "var(--accent-yellow)";
                    els.msgSub.style.fontSize = "1.2rem";
                    UI.updateProfileUI();
                    UI.triggerLevelUpAnimation();
                } else {
                    UI.updateProfileUI();
                }

                saveCurrentUser(AppState.user);
                updateUserInDB(AppState.user);
                Storage.saveSession([{
                    username: AppState.user.username, score: AppState.currentScore,
                    avgTime: AppState.reactionTimes.length ? (AppState.reactionTimes.reduce((a,b)=>a+b,0)/AppState.reactionTimes.length).toFixed(0) : '---',
                    bestTime: AppState.reactionTimes.length ? Math.min(...AppState.reactionTimes).toFixed(0) : '---',
                    xp: xpEarned
                }], 'Ranked');
            }
            
            // PENTING: Panggil showResultModal di akhir
            UI.showResultModal();

        } catch(e) {
            console.error("Error di endGame:", e);
            // Fallback jika error, tetap tampilkan modal
            UI.showResultModal();
        }
    },
    backToLobby: () => {
        if(els.resModal) {
            els.resModal.classList.remove('show');
            els.resModal.style.display = 'none';
        }
        showScreen('lobby');
        UI.updateProfileUI(); 
        UI.initIconPicker();
    }
};

const Storage = {
    saveSession: (players, mode) => {
        const key = 'reactionDuel_sessions';
        const sessions = JSON.parse(localStorage.getItem(key) || '[]');
        sessions.unshift({
            timestamp: new Date().toISOString(), players: players, mode: mode
        });
        if(sessions.length > 50) sessions.pop();
        localStorage.setItem(key, JSON.stringify(sessions));
    }
};

const Chat = {
    handleKey: (e) => {
        if(e.key === 'Enter' && els.chatInput) {
            const msg = els.chatInput.value.trim();
            if(msg) {
                const div = document.createElement('div');
                div.className = 'chat-msg';
                div.innerHTML = `<strong>${AppState.user.username}:</strong> ${msg}`;
                els.chatBox.appendChild(div); els.chatBox.scrollTop = els.chatBox.scrollHeight;
                els.chatInput.value = '';
            }
        }
    }
};

document.addEventListener('DOMContentLoaded', () => {
    initDOM();
    if(els.btnLogin) els.btnLogin.onclick = Auth.login;
    if(els.btnRegister) els.btnRegister.onclick = Auth.register;
    if(els.btnGuest) els.btnGuest.onclick = Auth.loginGuest;
    if(document.getElementById('sum-sessions')) Dashboard.init();
    else if (document.getElementById('login-screen')) Auth.checkSession();
});

const Dashboard = {
    init: () => {
        const sessions = JSON.parse(localStorage.getItem('reactionDuel_sessions') || '[]');
        els.sumSessions.textContent = sessions.length;
        const allTimes = sessions.flatMap(s => s.players.map(p => parseFloat(p.avgTime))).filter(Boolean);
        if(allTimes.length > 0) {
            els.sumAvg.innerHTML = (allTimes.reduce((a,b)=>a+b,0)/allTimes.length).toFixed(0) + "<span class='unit' style='font-size:0.5em'> ms</span>";
            els.sumBest.innerHTML = Math.min(...allTimes).toFixed(0) + "<span class='unit' style='font-size:0.5em'> ms</span>";
        }
        if(els.histList) {
            els.histList.innerHTML = sessions.slice(0, 10).map(s => {
                const winner = s.players.sort((a,b) => b.score - a.score)[0];
                return `
                <div class="history-item">
                    <div>
                        <div style="font-size:0.8rem; color:rgba(255,255,255,0.5);">${new Date(s.timestamp).toLocaleString('id-ID')}</div>
                        <div style="margin-top:5px; font-size:0.9rem;">XP: <span style="color:var(--accent-green)">${s.players[0].xp || 0}</span></div>
                    </div>
                    <div class="history-stats">
                        <div>Skor: <span>${winner.score}</span></div>
                        <div class="winner-tag">${winner.username}</div>
                    </div>
                </div>`;
            }).join('');
        }
        if(window.Chart && els.chartTrend) {
            new Chart(els.chartTrend.getContext('2d'), {
                type: 'line',
                data: {
                    labels: sessions.slice(0,10).reverse().map((_,i)=>i+1),
                    datasets: [{
                        label: 'Avg Time (ms)', data: sessions.slice(0,10).reverse().map(s => s.players[0]?.avgTime || 0),
                        borderColor: '#00f5ff', tension: 0.4
                    }]
                },
                options: { plugins: { legend: {display:false} }, scales: { y: {beginAtZero:false} } }
            });
        }
    }
};