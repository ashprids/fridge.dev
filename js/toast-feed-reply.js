(() => {
    const form=document.querySelector('#feed-reply-form[data-toast-reply-post]');
    if(!form || form.dataset.toastGeneratorBound)return;
    form.dataset.toastGeneratorBound='1';
    const generate=form.querySelector('[data-toast-generate-reply]');
    const editor=form.querySelector('[name="reply_content"]');
    const submit=form.querySelector('button[type="submit"]');
    const token=form.querySelector('[name="toast_reply_token"]');
    const parent=form.querySelector('[name="parent_reply_id"]');
    const notice=form.querySelector('[data-toast-reply-status]');
    let request=null, version=0;
    const sync=()=>{submit.disabled=editor.disabled||editor.readOnly||!editor.value.trim();};
    form.addEventListener('feed-reply-target-change',()=>{
        version++;request?.abort();request=null;
        editor.value='';editor.readOnly=true;token.value='';generate.disabled=editor.disabled;
        notice.textContent='Generate a reply for the selected target.';sync();
    });
    editor.addEventListener('input',sync);
    generate.addEventListener('click',async()=>{
        if(generate.disabled)return;
        const current=++version;
        request=new AbortController();generate.disabled=true;submit.disabled=true;editor.readOnly=true;
        notice.textContent='Generating reply…';
        try{
            const body=new URLSearchParams({post_id:form.dataset.toastReplyPost,parent_reply_id:parent.value,csrf_token:form.querySelector('[name="csrf_token"]').value});
            const response=await fetch('/api/toast-feed-reply/',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body,signal:request.signal});
            const data=await response.json();
            if(current!==version||!form.isConnected)return;
            if(!response.ok||!data.ok)throw new Error(data.error||'Could not generate reply.');
            editor.value=data.reply;token.value=data.token;editor.readOnly=false;
            notice.textContent='Edit the generated reply, then press reply to post it.';editor.focus();
        }catch(error){if(current===version&&error.name!=='AbortError'){notice.textContent=error.message;editor.readOnly=!token.value;}}
        finally{if(current===version){generate.disabled=editor.disabled;request=null;sync();}}
    });
    form.addEventListener('submit',event=>{if(submit.disabled||!token.value)event.preventDefault();});
    sync();
})();
