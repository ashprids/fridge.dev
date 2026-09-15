(function(){
function initChat(){
    var root=document.querySelector(".chat-view");
    if(!root||root.dataset.chatBound==="1")return;
    root.dataset.chatBound="1";
    var id=root.getAttribute("data-chat-id");
    var endpoint=root.getAttribute("data-chat-endpoint")||("/chat/"+id);
    var viewPath=root.getAttribute("data-chat-view-path")||endpoint;
    var imageOnly=root.getAttribute("data-image-only")==="1";
    var csrf=root.getAttribute("data-csrf")||"";
    var serverSendBlocked=root.getAttribute("data-send-blocked")==="1";
    var quotaReached=root.getAttribute("data-quota-reached")==="1";
    var sending=false;
    var canManage=root.getAttribute("data-can-manage")==="1";
    var postingRestricted=root.getAttribute("data-posting-restricted")==="1";
    var presenceEl=document.getElementById("chat-presence");
    var typingEl=document.getElementById("chat-typing-indicator");
    var messagesEl=document.getElementById("chat-messages");
    var form=document.querySelector(".chat-send-form");
    var textarea=form?form.querySelector("[name='message']"):null;
    var defaultPlaceholder=textarea?textarea.getAttribute("placeholder")||"message":"message";
    var replyInput=form?form.querySelector("[name='replyTo']"):null;
    var fileInput=form?form.querySelector("[name='attachment']"):null;
    var attachmentKindInput=form?form.querySelector("[name='attachmentKind']"):null;
    var fileIndicator=form?form.querySelector(".chat-file-indicator"):null;
    var sendButton=form?form.querySelector("button[type='submit']"):null;
    var holdVoiceButton=form?form.querySelector(".chat-hold-voice-button"):null;
    var replyPreview=form?form.querySelector(".chat-reply-compose"):null;
    var replyName=replyPreview?replyPreview.querySelector("strong"):null;
    var replyText=replyPreview?replyPreview.querySelector("span"):null;
    var replyCancel=replyPreview?replyPreview.querySelector("button"):null;
    var emojiButton=form?form.querySelector(".chat-emoji-button"):null;
    var attachButton=form?form.querySelector(".chat-attach-button"):null;
    var attachMenu=form?form.querySelector(".chat-attach-menu"):null;
    var voiceRecorderEl=form?form.querySelector(".chat-voice-recorder"):null;
    var menu=document.querySelector(".chat-context-menu");
    var emojiPicker=document.querySelector(".chat-emoji-picker");
    var emojiSearch=emojiPicker?emojiPicker.querySelector(".chat-emoji-search"):null;
    var emojiGrid=emojiPicker?emojiPicker.querySelector(".chat-emoji-grid"):null;
    var pickerMode="insert";
    var pickerMessageId="";
    var viewerRole=root.getAttribute("data-viewer-role")||"";
    var lastMessageId="";
    var lastMessageCount=0;
    var lastRevision="";
    var alertAudio=null;
    var unreadCount=0;
    var originalTitle=document.title;
    var deleteWarningAcknowledged=false;
    var currentlyTyping=false;
    var conversationEndedHandled=false;
    var chatTimers=[];
    var swipeState=null;
    var suppressMessageClick=false;
    var lastTypingSentAt=0;
    var typingIdleTimer=null;
    var viewportFrame=0;
    var EMOJI_DATA_URL="https://cdn.jsdelivr.net/npm/emojibase-data@16.0.3/en/data.json";
    function chatIsConnected(){if(root.isConnected)return true;if(!conversationEndedHandled){conversationEndedHandled=true;chatTimers.forEach(function(timer){clearInterval(timer);});chatTimers=[];}return false;}
    function isViewingThisChat(){var path=(window.location.pathname||"").replace(/\/+$/,"");return root.isConnected&&path===viewPath.replace(/\/+$/,"");}
    function handleConversationMissing(){if(conversationEndedHandled)return;conversationEndedHandled=true;chatTimers.forEach(function(timer){clearInterval(timer);});chatTimers=[];var selfEnded=false;try{var key="fridg3-chat-ended-self-"+id;selfEnded=sessionStorage.getItem(key)==="1";sessionStorage.removeItem(key);}catch(error){}var shortcut=document.getElementById("sidebar-active-chat");if(shortcut)shortcut.remove();if(isViewingThisChat()){window.location.href=viewPath;return;}if(!selfEnded){var accountLinked=root.getAttribute("data-account-linked-recipient")==="1";if(accountLinked&&typeof window.syncNotificationsSidebarButton==="function")window.syncNotificationsSidebarButton();else if(typeof window.showTransientSiteNotification==="function")window.showTransientSiteNotification("conversation ended","your private chat was ended and is no longer available.","/notifications");else if(typeof window.showSiteNotice==="function")window.showSiteNotice("conversation ended","your private chat was ended and is no longer available.");}}
    var quickEmojiOrder=["👍","👎","❤️","😮","😆","🔥","💩"];
    var fallbackEmojiItems=[
        {emoji:"👍",label:"thumbs up",tags:["yes","approve"]},
        {emoji:"👎",label:"thumbs down",tags:["no","disapprove"]},
        {emoji:"❤️",label:"red heart",tags:["love"]},
        {emoji:"😮",label:"face with open mouth",tags:["wow","surprised"]},
        {emoji:"😆",label:"grinning squinting face",tags:["laugh"]},
        {emoji:"🔥",label:"fire",tags:["hot"]},
        {emoji:"💩",label:"pile of poo",tags:["poop"]},
        {emoji:"😀",label:"grinning face",tags:["smile","happy"]},
        {emoji:"😂",label:"face with tears of joy",tags:["laugh","funny"]},
        {emoji:"😭",label:"loudly crying face",tags:["cry","sad"]},
        {emoji:"✨",label:"sparkles",tags:["shine"]},
        {emoji:"🎉",label:"party popper",tags:["party","celebrate"]},
        {emoji:"💀",label:"skull",tags:["dead"]},
        {emoji:"🚀",label:"rocket",tags:["launch"]}
    ];
    try{deleteWarningAcknowledged=localStorage.getItem("fridg3-chat-delete-warning-acknowledged")==="1";}catch(error){}
    var emojiItems=fallbackEmojiItems.slice();
    var emojiFilteredItems=[];
    var emojiRenderedCount=0;
    var emojiBatchSize=96;
    function label(role){return role==="manager"?"fridge":(root.getAttribute("data-recipient-name")||"recipient");}
    function scrollMessages(force){if(!messagesEl)return;var nearBottom=messagesEl.scrollHeight-messagesEl.scrollTop-messagesEl.clientHeight<110;if(force||nearBottom){messagesEl.scrollTop=messagesEl.scrollHeight;}}
    function isChatActive(){return (!document.visibilityState||document.visibilityState==="visible")&&document.hasFocus();}
    function updateUnreadTitle(){document.title=unreadCount>0?"("+unreadCount+") "+originalTitle:originalTitle;}
    function markChatRead(){if(!messagesEl)return;var incoming=messagesEl.querySelectorAll(".chat-message-other[data-message-id]");var latest=incoming.length?incoming[incoming.length-1].getAttribute("data-message-id")||"":"";try{if(latest)localStorage.setItem("fridg3-chat-seen-"+id,latest);else localStorage.removeItem("fridg3-chat-seen-"+id);}catch(error){}}
    function clearUnread(){markChatRead();if(unreadCount<1)return;unreadCount=0;updateUnreadTitle();}
    function playMessageAlert(){if(isChatActive())return;if(!alertAudio){alertAudio=new Audio("/chat/alert.ogg");alertAudio.preload="auto";}try{alertAudio.currentTime=0;var playPromise=alertAudio.play();if(playPromise&&typeof playPromise.catch==="function"){playPromise.catch(function(){});}}catch(error){}}
    function trackIncomingMessages(data,force){if(force||!data||!data.lastMessageId||!lastMessageId)return;if(data.lastMessageId!==lastMessageId&&data.lastMessageSender&&data.lastMessageSender!==viewerRole){if(!isChatActive()){unreadCount+=Math.max(1,Number(data.count||0)-lastMessageCount);updateUnreadTitle();}playMessageAlert();}}
    function syncComposer(data){if(data&&typeof data.sendBlocked==="boolean")serverSendBlocked=data.sendBlocked;if(data&&data.dailyLimit!==undefined)quotaReached=Number(data.dailyCount)>=Number(data.dailyLimit);if(data&&data.ok===false&&data.quotaResetAt)quotaReached=true;var disabled=postingRestricted||serverSendBlocked||sending;if(textarea){textarea.disabled=disabled;if(quotaReached)textarea.placeholder="daily message limit reached";else if(sending||serverSendBlocked)textarea.placeholder="waiting for toast...";else textarea.placeholder=defaultPlaceholder;}if(fileInput)fileInput.disabled=disabled;if(sendButton)sendButton.disabled=disabled;if(emojiButton)emojiButton.disabled=disabled;if(attachButton)attachButton.disabled=disabled;if(holdVoiceButton)holdVoiceButton.disabled=disabled;if(disabled){closeAttachMenu();closePicker();}}
    var typingRevealTimer=null;
    var typingDeadline=null;
    var typingActive=false;
    function renderPresence(data){
        if(!data||!data.ok||!root.isConnected)return;
        syncComposer(data);
        var status=data.otherStatus||(data.otherOnline?"online":(data.otherAway?"away":"offline"));
        if(presenceEl){presenceEl.className="chat-presence chat-presence-"+status;presenceEl.textContent=label(data.otherRole)+" is "+status;}
        if(!typingEl)return;
        typingActive=!!data.otherTyping;
        function hideTyping(){typingEl.textContent="";typingEl.style.display="none";}
        function revealTyping(){typingRevealTimer=null;if(typingActive&&root.isConnected){typingEl.textContent=label(data.otherRole)+" is typing...";typingEl.style.display="block";}}
        if(!typingActive){clearTimeout(typingRevealTimer);typingRevealTimer=null;typingDeadline=null;hideTyping();return;}
        var deadline=Number(data.typingStartsAtMs||0);
        if(typingDeadline===deadline)return;
        typingDeadline=deadline;
        clearTimeout(typingRevealTimer);
        var remaining=Math.max(0,deadline-Number(data.serverTimeMs||Date.now()));
        if(remaining>0){hideTyping();typingRevealTimer=setTimeout(revealTyping,remaining);}else revealTyping();
    }
    var typingObserver=new MutationObserver(function(){if(!root.isConnected){clearTimeout(typingRevealTimer);typingActive=false;typingObserver.disconnect();}});
    typingObserver.observe(document.body,{childList:true,subtree:true});
    function formatMediaTime(seconds){seconds=Number(seconds||0);if(!isFinite(seconds)||seconds<0)seconds=0;var mins=Math.floor(seconds/60);var secs=Math.floor(seconds%60);return mins+":"+(secs<10?"0":"")+secs;}
    function initChatMediaPlayers(){
        if(!messagesEl)return;
        messagesEl.querySelectorAll(".chat-attachment-media").forEach(function(wrap){
            if(wrap.dataset.mediaBound==="1")return;
            var media=wrap.querySelector(".chat-media-element");
            var controls=wrap.querySelector(".chat-media-player");
            if(!media||!controls)return;
            wrap.dataset.mediaBound="1";
            var play=controls.querySelector(".chat-media-play");
            var playIcon=play?play.querySelector("i"):null;
            var seek=controls.querySelector(".chat-media-seek");
            var time=controls.querySelector(".chat-media-time");
            var mute=controls.querySelector(".chat-media-mute");
            var muteIcon=mute?mute.querySelector("i"):null;
            var volume=controls.querySelector(".chat-media-volume");
            var speed=controls.querySelector(".chat-media-speed");
            var speedLabel=speed?speed.querySelector(".chat-media-speed-label"):null;
            var playbackSpeeds=[1,1.5,2];
            function updatePlay(){if(!playIcon)return;playIcon.classList.toggle("fa-play",media.paused);playIcon.classList.toggle("fa-pause",!media.paused);}
            function updateTime(){var duration=isFinite(media.duration)?media.duration:0;if(seek&&!seek.matches(":active")){seek.value=duration>0?String(Math.round((media.currentTime/duration)*1000)):"0";}if(time){time.textContent=formatMediaTime(media.currentTime)+" / "+formatMediaTime(duration);}}
            function updateMute(){if(!muteIcon)return;var muted=media.muted||media.volume===0;muteIcon.classList.toggle("fa-volume-high",!muted);muteIcon.classList.toggle("fa-volume-xmark",muted);if(volume)volume.value=String(media.muted?0:media.volume);}
            function updateSpeed(){if(!speedLabel)return;var rate=playbackSpeeds.indexOf(media.playbackRate)!==-1?media.playbackRate:1;speedLabel.textContent=rate+"x";if(speed)speed.setAttribute("aria-label","playback speed "+rate+"x");}
            if(play){play.addEventListener("click",function(){if(media.paused){messagesEl.querySelectorAll(".chat-media-element").forEach(function(other){if(other!==media)other.pause();});media.play().catch(function(){});}else{media.pause();}});}
            if(seek){seek.addEventListener("input",function(){if(!isFinite(media.duration)||media.duration<=0)return;media.currentTime=(Number(seek.value||0)/1000)*media.duration;updateTime();});}
            if(mute){mute.addEventListener("click",function(){media.muted=!media.muted;updateMute();});}
            if(volume){volume.addEventListener("input",function(){var value=Math.max(0,Math.min(1,Number(volume.value||0)));media.volume=value;media.muted=value===0;updateMute();});}
            if(speed){speed.addEventListener("click",function(){var currentIndex=playbackSpeeds.indexOf(media.playbackRate);var nextIndex=currentIndex===-1?0:(currentIndex+1)%playbackSpeeds.length;media.playbackRate=playbackSpeeds[nextIndex];updateSpeed();});}
            media.addEventListener("loadedmetadata",updateTime);
            media.addEventListener("timeupdate",updateTime);
            media.addEventListener("play",updatePlay);
            media.addEventListener("pause",updatePlay);
            media.addEventListener("ended",function(){updatePlay();updateTime();});
            media.addEventListener("volumechange",updateMute);
            media.addEventListener("ratechange",updateSpeed);
            updatePlay();
            updateTime();
            updateMute();
            updateSpeed();
        });
    }
    function renderMessages(data,force){if(!messagesEl||!data||!data.ok)return;if(data.pending===false){typingActive=false;typingDeadline=null;clearTimeout(typingRevealTimer);typingRevealTimer=null;if(typingEl){typingEl.textContent="";typingEl.style.display="none";}}syncComposer(data);var revision=data.revision||"";if((revision&&revision!==lastRevision)||data.lastMessageId!==lastMessageId||messagesEl.innerHTML===""){var incoming=!!(lastMessageId&&data.lastMessageId&&data.lastMessageId!==lastMessageId&&data.lastMessageSender&&data.lastMessageSender!==viewerRole);trackIncomingMessages(data,force);messagesEl.innerHTML=data.html;initChatMediaPlayers();lastMessageId=data.lastMessageId||"";lastMessageCount=Number(data.count||0);lastRevision=revision;scrollMessages(!!force||incoming);if(isChatActive())clearUnread();}}
    function showRecipientIntro(){if(root.getAttribute("data-show-recipient-intro")!=="1"||typeof window.showSitePopup!=="function")return;root.setAttribute("data-show-recipient-intro","0");var accountLinked=root.getAttribute("data-account-linked-recipient")==="1";var ownership=accountLinked?"<p>this chat is linked to your fridge.dev account, so you can reopen it from the active chat button whenever you are logged in.</p>":"<p>this chat is locked to this browser. clearing its chat cookie or opening the link in another browser may prevent you from getting back in unless an administrator revokes and reissues access.</p>";window.showSitePopup({title:"welcome to your private chat",html:ownership+"<p>messages and attachments are kept in an encrypted chat file. ending the chat permanently deletes the conversation and its stored attachments.</p><p><strong>using chat</strong></p><ul><li>send text, images, files, or voice notes from the composer</li><li>on desktop, right-click a message for reply, reaction, and deletion controls</li><li>on mobile, hold a message to react; swipe right to reply</li><li>swipe your own message right to delete it</li><li>swipe the other person's message left to hide or restore it for your view only</li></ul><p>hidden messages remain visible to the other person. deleted messages become placeholders for everyone.</p>",okText:"start chatting"});}
    function syncFileIndicator(){if(!fileIndicator||!fileInput)return;var file=fileInput.files&&fileInput.files[0]?fileInput.files[0]:null;var isVoice=attachmentKindInput&&attachmentKindInput.value==="voice";fileIndicator.textContent=file?((isVoice?"voice note: ":"attached: ")+file.name):"";fileIndicator.style.display=file?"block":"none";}
    function jsonFetch(url,options){return fetch(url,options).then(function(response){return response.json().then(function(data){if(!response.ok){data.ok=false;}if(imageOnly&&data.offline){window.location.replace("/others/toast-discord-bot?chat_offline=1");}return data;});});}
    function appendCsrf(body){if(csrf)body.append("csrf",csrf);}
    function presenceBody(typingOverride){var body=new URLSearchParams();var active=isChatActive();var typing=typeof typingOverride==="boolean"?typingOverride:currentlyTyping;body.append("state",active?"online":"away");body.append("typing",(active&&typing)?"1":"0");return body;}
    function ping(){if(conversationEndedHandled||!chatIsConnected())return;jsonFetch(endpoint+"?action=presence",{method:"POST",body:presenceBody(),cache:"no-store",credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/x-www-form-urlencoded"}}).then(function(data){if(data.exists===false){handleConversationMissing();return;}renderPresence(data);}).catch(function(){});}
    function refreshPresence(){if(conversationEndedHandled||!chatIsConnected())return;jsonFetch(endpoint+"?action=presence",{cache:"no-store",credentials:"same-origin",headers:{Accept:"application/json"}}).then(function(data){if(data.exists===false){handleConversationMissing();return;}renderPresence(data);}).catch(function(){});}
    function pingAway(){var body=new URLSearchParams();body.append("state","away");if(navigator.sendBeacon){navigator.sendBeacon(endpoint+"?action=presence",body);return;}fetch(endpoint+"?action=presence",{method:"POST",body:body,credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/x-www-form-urlencoded"}}).catch(function(){});}
    function refreshMessages(force){if(conversationEndedHandled||!chatIsConnected())return;jsonFetch(endpoint+"?action=messages",{cache:"no-store",credentials:"same-origin",headers:{Accept:"application/json"}}).then(function(data){if(data.exists===false){handleConversationMissing();return;}renderMessages(data,force);}).catch(function(){});}
    function hasFile(){return fileInput&&fileInput.files&&fileInput.files.length>0;}
    function messageSummary(message){var source=message.querySelector(".chat-message-quote-source");var body=source?source.textContent.trim():"";if(!body){body="message";}return body;}
    function messageAuthor(message){var author=message.querySelector(".chat-message-meta strong");return author?author.textContent.trim():"message";}
    function setReply(message){if(!message||!replyInput||!replyPreview)return;replyInput.value=message.getAttribute("data-message-id")||"";if(replyName)replyName.textContent=messageAuthor(message);if(replyText)replyText.textContent=messageSummary(message);replyPreview.style.display="grid";textarea&&textarea.focus();}
    function clearReply(){if(replyInput)replyInput.value="";if(replyPreview)replyPreview.style.display="none";}
    function clearLongPress(state){if(state&&state.longPressTimer){clearTimeout(state.longPressTimer);state.longPressTimer=null;}}
    function resetSwipe(message){if(!message)return;message.classList.remove("chat-message-swiping","chat-message-swipe-ready","chat-message-swipe-delete","chat-message-swipe-hide");message.style.removeProperty("--chat-swipe-x");}
    function beginMessageSwipe(event){if(event.pointerType==="mouse"&&event.button!==0)return;var message=event.target.closest(".chat-message[data-message-id]");if(!message||message.getAttribute("data-message-deleted")==="1"||event.target.closest("button,input,.chat-media-player"))return;var own=message.getAttribute("data-message-own")==="1";swipeState={pointerId:event.pointerId,message:message,startX:event.clientX,startY:event.clientY,own:own,direction:own?0:1,action:"reply",dragging:false,ready:false,longPressed:false,longPressTimer:null,threshold:(event.pointerType==="touch"||isMobileChat())?20:44};if(isMobileChat()&&event.pointerType!=="mouse"){swipeState.longPressTimer=setTimeout(function(){if(!swipeState||swipeState.dragging)return;swipeState.longPressed=true;suppressMessageClick=true;closeMenu();var rect=message.getBoundingClientRect();openPicker("react",message.getAttribute("data-message-id")||"",Math.max(8,Math.min(event.clientX,window.innerWidth-20)),Math.max(8,Math.min(event.clientY,rect.bottom)));if(navigator.vibrate)navigator.vibrate(8);},480);}}
    function moveMessageSwipe(event){if(!swipeState||event.pointerId!==swipeState.pointerId||swipeState.longPressed)return;var dx=event.clientX-swipeState.startX;var dy=event.clientY-swipeState.startY;if(Math.abs(dx)>7||Math.abs(dy)>7)clearLongPress(swipeState);if(!swipeState.dragging){if(Math.abs(dy)>18&&Math.abs(dy)>Math.abs(dx)*1.75){clearLongPress(swipeState);swipeState=null;return;}if(Math.abs(dx)<3)return;var direction=Math.sign(dx);swipeState.direction=direction;swipeState.action=swipeState.own&&direction===1?"delete":(!swipeState.own&&direction===-1?"hide":"reply");swipeState.message.classList.toggle("chat-message-swipe-delete",swipeState.action==="delete");swipeState.message.classList.toggle("chat-message-swipe-hide",swipeState.action==="hide");swipeState.dragging=true;swipeState.message.classList.add("chat-message-swiping");try{swipeState.message.setPointerCapture(event.pointerId);}catch(error){}}
        event.preventDefault();var distance=Math.min(64,Math.abs(dx));var offset=swipeState.direction*distance;swipeState.message.style.setProperty("--chat-swipe-x",offset+"px");var ready=distance>=swipeState.threshold;if(ready!==swipeState.ready){swipeState.ready=ready;swipeState.message.classList.toggle("chat-message-swipe-ready",ready);if(ready&&navigator.vibrate)navigator.vibrate(8);}}
    function endMessageSwipe(event){if(!swipeState||event.pointerId!==swipeState.pointerId)return;var state=swipeState;clearLongPress(state);swipeState=null;if(state.longPressed){setTimeout(function(){suppressMessageClick=false;},0);return;}if(!state.dragging)return;suppressMessageClick=true;setTimeout(function(){suppressMessageClick=false;},0);resetSwipe(state.message);if(state.ready){closeMenu();closePicker();if(state.action==="delete"){deleteMessage(state.message.getAttribute("data-message-id")||"");}else if(state.action==="hide"){hideMessage(state.message.getAttribute("data-message-id")||"");}else{setReply(state.message);}}}
    function cancelMessageSwipe(event){if(!swipeState||event.pointerId!==swipeState.pointerId)return;var message=swipeState.message;clearLongPress(swipeState);swipeState=null;resetSwipe(message);}
    function sendTypingState(active,force){currentlyTyping=!!(active&&isChatActive());var now=Date.now();if(!force&&currentlyTyping&&now-lastTypingSentAt<1400)return;lastTypingSentAt=now;jsonFetch(endpoint+"?action=presence",{method:"POST",body:presenceBody(currentlyTyping),cache:"no-store",credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/x-www-form-urlencoded"}}).then(function(data){if(data.exists===false){handleConversationMissing();return;}renderPresence(data);}).catch(function(){});}
    function queueTyping(){if(!textarea)return;var active=textarea.value.trim()!=="";sendTypingState(active,false);if(typingIdleTimer)clearTimeout(typingIdleTimer);typingIdleTimer=setTimeout(function(){sendTypingState(false,true);},2800);}
    function closeMenu(){if(menu)menu.style.display="none";}
    function closeAttachMenu(){if(attachMenu)attachMenu.style.display="none";}
    function closePicker(){if(emojiPicker)emojiPicker.style.display="none";pickerMessageId="";}
    function isMobileChat(){return !!(document.body&&document.body.classList&&document.body.classList.contains("mobile-template"))||window.matchMedia("(max-width: 720px)").matches;}
    function syncMobileViewport(){if(!document.body||!document.body.classList.contains("mobile-template"))return;if(viewportFrame)cancelAnimationFrame(viewportFrame);viewportFrame=requestAnimationFrame(function(){viewportFrame=0;var viewport=window.visualViewport;var height=viewport?viewport.height:window.innerHeight;var top=viewport?viewport.offsetTop:0;var left=viewport?viewport.offsetLeft:0;document.documentElement.style.setProperty("--chat-mobile-height",Math.round(height)+"px");document.documentElement.style.setProperty("--chat-mobile-top",Math.round(top)+"px");document.documentElement.style.setProperty("--chat-mobile-left",Math.round(left)+"px");});}
    function settleMobileViewport(){syncMobileViewport();setTimeout(syncMobileViewport,80);setTimeout(function(){window.scrollTo(0,0);syncMobileViewport();},260);}
    function placeBox(box,x,y){if(!box||!root)return;box.style.display="block";var rect=box.getBoundingClientRect();var fixed=window.getComputedStyle(box).position==="fixed";if(fixed){box.style.left=Math.max(8,Math.min(x,window.innerWidth-rect.width-8))+"px";box.style.top=Math.max(8,Math.min(y,window.innerHeight-rect.height-8))+"px";return;}var rootRect=root.getBoundingClientRect();var localX=x-rootRect.left;var localY=y-rootRect.top;var maxLeft=Math.max(8,root.clientWidth-rect.width-8);var maxTop=Math.max(8,root.clientHeight-rect.height-8);box.style.left=Math.max(8,Math.min(localX,maxLeft))+"px";box.style.top=Math.max(8,Math.min(localY,maxTop))+"px";}
    function openMenu(message,x,y){if(!menu||!message)return;menu.dataset.messageId=message.getAttribute("data-message-id")||"";var deleteButton=menu.querySelector('[data-chat-action="delete"]');if(deleteButton){deleteButton.style.display=(canManage||message.getAttribute("data-message-own")==="1")?"block":"none";}placeBox(menu,x,y);}
    function emojiFromHexcode(hexcode){return String(hexcode||"").split("-").map(function(part){var code=parseInt(part,16);return code?String.fromCodePoint(code):"";}).join("");}
    function emojiTagList(value){if(Array.isArray(value))return value.map(String);if(typeof value==="string"&&value)return [value];if(value&&typeof value==="object"){return Object.keys(value).reduce(function(tags,key){var next=value[key];return tags.concat(Array.isArray(next)?next.map(String):[String(next)]);},[]);}return [];}
    function normalizeEmojiItem(item){var emoji=item&&typeof item.emoji==="string"&&item.emoji?item.emoji:emojiFromHexcode(item&&item.hexcode);var label=String(item&&item.label||"emoji");if(!emoji)return null;var tags=emojiTagList(item&&item.tags).concat(emojiTagList(item&&item.shortcodes));return {emoji:emoji,label:label,tags:tags,group:Number(item&&item.group||0),order:Number(item&&item.order||0)};}
    function loadEmojiData(){if(!window.fetch)return;fetch(EMOJI_DATA_URL,{cache:"force-cache"}).then(function(response){if(!response.ok)throw new Error("emoji data failed");return response.json();}).then(function(data){if(!Array.isArray(data))return;var loaded=[];data.forEach(function(item){var normalized=normalizeEmojiItem(item);if(normalized)loaded.push(normalized);if(item&&Array.isArray(item.skins)){item.skins.forEach(function(skin){var skinItem=Object.assign({},item,skin,{label:skin.label||item.label});var normalizedSkin=normalizeEmojiItem(skinItem);if(normalizedSkin)loaded.push(normalizedSkin);});}});if(loaded.length){emojiItems=loaded;if(emojiPicker&&emojiPicker.style.display==="block"){renderEmojiList(emojiSearch?emojiSearch.value:"");}}}).catch(function(){});}
    function firstGrapheme(value){value=String(value||"").trim();if(!value)return "";if(window.Intl&&Intl.Segmenter){var segments=new Intl.Segmenter(undefined,{granularity:"grapheme"}).segment(value);var first=segments[Symbol.iterator]().next();return first.done?"":first.value.segment;}return Array.from(value)[0]||"";}
    function looksEmoji(value){return /[\u203C-\u3299]|\uD83C[\uD000-\uDFFF]|\uD83D[\uD000-\uDFFF]|\uD83E[\uD000-\uDFFF]/u.test(value);}
    function emojiQueryCandidate(query){var emoji=firstGrapheme(query);return emoji&&looksEmoji(emoji)?{emoji:emoji,label:"typed emoji",tags:["custom"],group:-1,order:-1}:null;}
    function appendEmojiChunk(){if(!emojiGrid)return;var end=Math.min(emojiRenderedCount+emojiBatchSize,emojiFilteredItems.length);var fragment=document.createDocumentFragment();for(var i=emojiRenderedCount;i<end;i++){var item=emojiFilteredItems[i];var btn=document.createElement("button");btn.type="button";btn.textContent=item.emoji;btn.title=item.label;btn.setAttribute("data-emoji",item.emoji);fragment.appendChild(btn);}emojiGrid.appendChild(fragment);emojiRenderedCount=end;}
    function renderEmojiList(query){if(!emojiGrid)return;var q=(query||"").trim().toLowerCase();emojiGrid.innerHTML="";emojiGrid.scrollTop=0;emojiRenderedCount=0;var used={};var combined=[];var typed=emojiQueryCandidate(query);if(typed)combined.push(typed);var quick=[];if(!q){quickEmojiOrder.forEach(function(emoji){var item=fallbackEmojiItems.find(function(candidate){return candidate.emoji===emoji;})||emojiItems.find(function(candidate){return candidate.emoji===emoji;});if(item)quick.push(item);});}var matches=emojiItems.filter(function(item){var haystack=(item.emoji+" "+item.label+" "+item.tags.join(" ")).toLowerCase();return !q||haystack.indexOf(q)!==-1;}).sort(function(a,b){var qa=quickEmojiOrder.indexOf(a.emoji);var qb=quickEmojiOrder.indexOf(b.emoji);if(qa!==-1||qb!==-1)return (qa===-1?999:qa)-(qb===-1?999:qb);return (a.order||0)-(b.order||0);});quick.concat(matches).forEach(function(item){if(used[item.emoji])return;used[item.emoji]=true;combined.push(item);});emojiFilteredItems=combined;appendEmojiChunk();}
    function openPicker(mode,messageId,x,y){if(!emojiPicker)return;pickerMode=mode;pickerMessageId=messageId||"";if(emojiSearch)emojiSearch.value="";renderEmojiList("");placeBox(emojiPicker,x,y);if(emojiSearch&&!isMobileChat())emojiSearch.focus();}
    function react(messageId,emoji){var body=new URLSearchParams();body.append("action","react");body.append("messageId",messageId);body.append("emoji",emoji);appendCsrf(body);jsonFetch(endpoint,{method:"POST",body:body,credentials:"same-origin",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest","Content-Type":"application/x-www-form-urlencoded"}}).then(function(data){if(data.exists===false){handleConversationMissing();return;}if(data.ok){renderMessages(data,false);}}).catch(function(){});}
    function confirmDeleteMessage(){if(deleteWarningAcknowledged)return Promise.resolve(true);return window.showSitePopup({title:"delete message?",detail:"this will replace the message with a deleted placeholder for everyone in the chat. you will only be asked once.",okText:"delete",cancelText:"cancel"});}
    function deleteMessage(messageId){if(!messageId)return;confirmDeleteMessage().then(function(confirmed){if(!confirmed)return;if(!deleteWarningAcknowledged){deleteWarningAcknowledged=true;try{localStorage.setItem("fridg3-chat-delete-warning-acknowledged","1");}catch(error){}}var body=new URLSearchParams();body.append("action","delete-message");body.append("messageId",messageId);appendCsrf(body);jsonFetch(endpoint,{method:"POST",body:body,credentials:"same-origin",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest","Content-Type":"application/x-www-form-urlencoded"}}).then(function(data){if(data.exists===false){handleConversationMissing();return;}if(data.ok){renderMessages(data,false);}else if(data.error){window.showSiteNotice ? window.showSiteNotice("chat error", data.error) : window.showSitePopup({title:"chat error", detail:data.error, okText:"ok"});}}).catch(function(){});});}
    function hideMessage(messageId){if(!messageId)return;var body=new URLSearchParams();body.append("action","hide-message");body.append("messageId",messageId);appendCsrf(body);jsonFetch(endpoint,{method:"POST",body:body,credentials:"same-origin",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest","Content-Type":"application/x-www-form-urlencoded"}}).then(function(data){if(data.exists===false){handleConversationMissing();return;}if(data.ok){renderMessages(data,false);}else if(data.error){window.showSiteNotice ? window.showSiteNotice("chat error",data.error) : window.showSitePopup({title:"chat error",detail:data.error,okText:"ok"});}}).catch(function(){});}
    var clearButton=root.querySelector('.toast-clear-chat');
    if(clearButton)clearButton.addEventListener('click',async function(){
        if(clearButton.disabled)return;
        var confirmed=window.showSitePopup?await window.showSitePopup({title:'clear chat?',detail:'Clear your chat and Toast’s memory? The conversation will remain available in admin history.',okText:'clear chat',cancelText:'cancel'}):window.confirm('Clear chat and Toast’s memory? Admin history will be kept.');
        if(!confirmed)return;
        clearButton.disabled=true;
        try{
            var body=new URLSearchParams({action:'clear-chat',csrf:csrf});
            var data=await jsonFetch(endpoint,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded'},body:body});
            if(!data.ok)throw new Error(data.error||'Could not clear chat.');
            clearReply();renderMessages(data,true);syncComposer(data);
        }catch(error){window.showSiteNotice?.('chat error',error.message);}
        finally{clearButton.disabled=false;}
    });
    function submitMessage(event){event.preventDefault();if(!form||!textarea||postingRestricted||serverSendBlocked||sending)return;var body=textarea.value.trim();if(body===""&&!hasFile())return;var isVoice=attachmentKindInput&&attachmentKindInput.value==="voice";if(hasFile()&&!isVoice&&fileInput.files[0].size>8388608){window.showSiteNotice ? window.showSiteNotice("file too big", "max size is 8 MB.") : window.showSitePopup({title:"file too big", detail:"max size is 8 MB.", okText:"ok"});return;}if(hasFile()&&isVoice&&fileInput.files[0].size>12000000){window.showSiteNotice ? window.showSiteNotice("voice note too big", "keep voice notes under 2 minutes.") : window.showSitePopup({title:"voice note too big", detail:"keep voice notes under 2 minutes.", okText:"ok"});return;}if(typingIdleTimer)clearTimeout(typingIdleTimer);sendTypingState(false,true);var payload=new FormData(form);sending=true;syncComposer();jsonFetch(form.getAttribute("action")||endpoint,{method:"POST",body:payload,credentials:"same-origin",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest"}}).then(function(data){if(data.exists===false){handleConversationMissing();return;}if(data.ok){textarea.value="";if(fileInput)fileInput.value="";if(attachmentKindInput)attachmentKindInput.value="";clearReply();syncFileIndicator();renderMessages(data,true);if(data.adminError&&window.showSiteNotice)window.showSiteNotice("Toast AI error (admin)",data.adminError);}else if(data.error){if(data.quotaResetAt)serverSendBlocked=true;syncComposer(data);window.showSiteNotice ? window.showSiteNotice("chat error", data.error) : window.showSitePopup({title:"chat error", detail:data.error, okText:"ok"});}}).catch(function(){if(!imageOnly)form.submit();else if(window.showSiteNotice)window.showSiteNotice("chat error","Toast could not be reached. try again in a moment.");}).finally(function(){sending=false;syncComposer();if(textarea&&!textarea.disabled)textarea.focus();});}
    if(form&&textarea){form.addEventListener("submit",submitMessage);textarea.addEventListener("focus",settleMobileViewport);textarea.addEventListener("input",queueTyping);textarea.addEventListener("blur",function(){if(typingIdleTimer)clearTimeout(typingIdleTimer);sendTypingState(false,true);settleMobileViewport();});textarea.addEventListener("keydown",function(event){if(event.key==="Enter"&&!event.shiftKey){event.preventDefault();if(form.requestSubmit){form.requestSubmit();}else{submitMessage(event);}}});}
    if(fileInput){fileInput.addEventListener("change",function(){if(imageOnly&&fileInput.files[0]&&!fileInput.files[0].type.startsWith("image/")){fileInput.value="";window.showSiteNotice&&window.showSiteNotice("image required","only image uploads are supported in this chat.");}if(attachmentKindInput)attachmentKindInput.value="";syncFileIndicator();});syncFileIndicator();}
    if(attachButton&&attachMenu){attachButton.addEventListener("click",function(event){event.preventDefault();closeMenu();closePicker();if(document.body&&document.body.classList.contains("mobile-template")){if(attachmentKindInput)attachmentKindInput.value="";if(fileInput){fileInput.accept="image/*";fileInput.value="";syncFileIndicator();fileInput.click();}return;}attachMenu.style.display=attachMenu.style.display==="block"?"none":"block";});attachMenu.addEventListener("click",function(event){var action=event.target.closest("[data-chat-compose-action]");if(!action)return;event.preventDefault();if(action.getAttribute("data-chat-compose-action")==="upload"){if(attachmentKindInput)attachmentKindInput.value="";if(fileInput){fileInput.value="";syncFileIndicator();fileInput.click();}closeAttachMenu();}else if(action.getAttribute("data-chat-compose-action")==="voice"){if(voiceRecorderEl){voiceRecorderEl.hidden=false;}closeAttachMenu();}});}
    if(voiceRecorderEl&&typeof window.fridgeCreateVoiceRecorder==="function"){window.fridgeCreateVoiceRecorder(voiceRecorderEl,function(file){var dt=new DataTransfer();dt.items.add(file);if(fileInput)fileInput.files=dt.files;if(attachmentKindInput)attachmentKindInput.value="voice";syncFileIndicator();});}
    if(holdVoiceButton){holdVoiceButton.addEventListener("click",function(event){event.preventDefault();closeMenu();closePicker();closeAttachMenu();if(!voiceRecorderEl)return;var shouldHide=!voiceRecorderEl.hidden;if(shouldHide){var activeRecordButton=voiceRecorderEl.querySelector('[data-voice-action="record"]');if(activeRecordButton&&!activeRecordButton.disabled&&activeRecordButton.querySelector(".fa-stop"))activeRecordButton.click();voiceRecorderEl.hidden=true;holdVoiceButton.setAttribute("aria-expanded","false");return;}voiceRecorderEl.hidden=false;holdVoiceButton.setAttribute("aria-expanded","true");var recordButton=voiceRecorderEl.querySelector('[data-voice-action="record"]');if(recordButton)recordButton.focus();});}
    if(replyCancel){replyCancel.addEventListener("click",clearReply);}
    if(emojiButton){emojiButton.addEventListener("click",function(event){event.preventDefault();closeMenu();closeAttachMenu();var rect=emojiButton.getBoundingClientRect();openPicker("insert","",rect.left,rect.top-330);});}
    if(messagesEl){messagesEl.addEventListener("pointerdown",beginMessageSwipe);messagesEl.addEventListener("pointermove",moveMessageSwipe,{passive:false});messagesEl.addEventListener("pointerup",endMessageSwipe);messagesEl.addEventListener("pointercancel",cancelMessageSwipe);messagesEl.addEventListener("contextmenu",function(event){var message=event.target.closest(".chat-message[data-message-id]");if(!message)return;event.preventDefault();if(!isMobileChat()&&message.getAttribute("data-message-deleted")!=="1"){closePicker();openMenu(message,event.clientX,event.clientY);}});messagesEl.addEventListener("click",function(event){if(suppressMessageClick){event.preventDefault();event.stopPropagation();return;}var ref=event.target.closest("[data-scroll-message]");if(ref){var target=messagesEl.querySelector('.chat-message[data-message-id="'+ref.getAttribute("data-scroll-message")+'"]');if(target){target.scrollIntoView({block:"center",behavior:"smooth"});target.classList.add("chat-message-highlight");setTimeout(function(){target.classList.remove("chat-message-highlight");},1200);}return;}var reaction=event.target.closest(".chat-reaction[data-message-id][data-emoji]");if(reaction){react(reaction.getAttribute("data-message-id"),reaction.getAttribute("data-emoji"));return;}});}
    if(menu){menu.addEventListener("click",function(event){var action=event.target.closest("[data-chat-action]");if(!action)return;event.stopPropagation();var message=messagesEl?messagesEl.querySelector('.chat-message[data-message-id="'+menu.dataset.messageId+'"]'):null;if(action.getAttribute("data-chat-action")==="reply"){setReply(message);closeMenu();}else if(action.getAttribute("data-chat-action")==="delete"){deleteMessage(menu.dataset.messageId);closeMenu();}else{var rect=menu.getBoundingClientRect();openPicker("react",menu.dataset.messageId,rect.left,rect.bottom+6);closeMenu();}});}
    if(emojiSearch){emojiSearch.addEventListener("input",function(){renderEmojiList(emojiSearch.value);});}
    if(emojiGrid){emojiGrid.addEventListener("scroll",function(){if(emojiRenderedCount<emojiFilteredItems.length&&emojiGrid.scrollTop+emojiGrid.clientHeight>=emojiGrid.scrollHeight-80){appendEmojiChunk();}});emojiGrid.addEventListener("click",function(event){var btn=event.target.closest("[data-emoji]");if(!btn)return;var emoji=btn.getAttribute("data-emoji");if(pickerMode==="react"&&pickerMessageId){react(pickerMessageId,emoji);}else if(textarea){var start=textarea.selectionStart||textarea.value.length;var end=textarea.selectionEnd||start;textarea.value=textarea.value.slice(0,start)+emoji+textarea.value.slice(end);textarea.focus();textarea.setSelectionRange(start+emoji.length,start+emoji.length);}closePicker();});}
    document.addEventListener("click",function(event){if(menu&&menu.style.display==="block"&&!event.target.closest(".chat-context-menu")&&!event.target.closest(".chat-message"))closeMenu();if(attachMenu&&attachMenu.style.display==="block"&&!event.target.closest(".chat-attach-menu")&&!event.target.closest(".chat-attach-button"))closeAttachMenu();if(emojiPicker&&emojiPicker.style.display==="block"&&!event.target.closest(".chat-emoji-picker")&&!event.target.closest(".chat-emoji-button"))closePicker();});
    document.addEventListener("keydown",function(event){if(event.key==="Escape"){closeMenu();closeAttachMenu();closePicker();}});
    chatTimers.push(setInterval(function(){if(conversationEndedHandled||!chatIsConnected())return;jsonFetch(endpoint+"?action=status",{cache:"no-store"}).then(function(data){if(!data.exists)handleConversationMissing();}).catch(function(){});},5000));
    document.addEventListener("visibilitychange",function(){if(!isChatActive())sendTypingState(false,true);ping();if(isChatActive()){clearUnread();refreshMessages(false);}});
    window.addEventListener("focus",function(){clearUnread();ping();refreshMessages(false);});
    window.addEventListener("blur",function(){sendTypingState(false,true);ping();});
    window.addEventListener("pagehide",function(){sendTypingState(false,true);pingAway();});
    if(document.body&&document.body.classList.contains("mobile-template")){settleMobileViewport();window.addEventListener("resize",settleMobileViewport);window.addEventListener("orientationchange",settleMobileViewport);if(window.visualViewport){window.visualViewport.addEventListener("resize",syncMobileViewport);}}
    syncComposer();loadEmojiData();initChatMediaPlayers();scrollMessages(true);ping();refreshMessages(true);showRecipientIntro();chatTimers.push(setInterval(ping,5000));chatTimers.push(setInterval(refreshPresence,1000));chatTimers.push(setInterval(function(){refreshMessages(false);},2000));
}
if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",initChat);}else{initChat();}
}());
