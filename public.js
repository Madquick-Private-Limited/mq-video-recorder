(function () {
  const root = document.getElementById('mq-video-root');
  if (!root) return;

  const REST = root.dataset.rest;
  const NONCE = root.dataset.nonce;

  // Controls
  const btnUpload   = document.getElementById('mqvm-upload');
  const btnRecord   = document.getElementById('mqvm-record');
  const btnStop     = document.getElementById('mqvm-stop');
  const inputFile   = document.getElementById('mqvm-file');

  // Lists / progress
  const listEl      = document.getElementById('mqvm-list');
  const progressWrap= document.getElementById('mqvm-progress');
  const bar         = document.getElementById('mqvm-bar');

  // Same-frame video + HUD
  const liveVideo   = document.getElementById('mqvm-live');
  const overlay     = document.getElementById('mqvm-overlay');
  const timerEl     = document.getElementById('mqvm-timer');
  const sizeEl      = document.getElementById('mqvm-size');
  const liveHint    = document.getElementById('mqvm-live-hint');

  // Confirm row under the same frame
  const confirmRow  = document.getElementById('mqvm-confirm-row');
  const btnUse      = document.getElementById('mqvm-use');
  const btnRetake   = document.getElementById('mqvm-retake');
  const metaEl      = document.getElementById('mqvm-meta');

  let mediaRecorder = null;
  let liveStream    = null;
  let recordedChunks= [];
  let recordedBlob  = null;
  let recordedMime  = 'video/webm';
  let recordTimeout = null;

  let tickHandle    = null;
  let startTs       = 0;
  let totalBytes    = 0;
  let playbackURL   = null;

  const MAX_FILE_BYTES = 300 * 1024 * 1024;
  const MAX_SECONDS    = 60;

  function api(path, opts = {}) {
    const headers = Object.assign({ 'X-WP-Nonce': NONCE }, opts.headers || {});
    return fetch(`${REST}${path}`, Object.assign({}, opts, { headers }));
  }

  function fmtTime(sec) {
    const s = Math.max(0, Math.floor(sec));
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${String(m).padStart(2,'0')}:${String(r).padStart(2,'0')}`;
  }

  function setProgress(pct) {
    progressWrap.hidden = false;
    bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
    if (pct >= 100) setTimeout(() => { progressWrap.hidden = true; bar.style.width = '0%'; }, 400);
  }

  async function refresh() {
    const res = await api('/my', { method: 'GET' });
    const data = await res.json();
    renderList(data);
  }

  function renderList(state) {
    const { items, limits, usage, max_file_mb } = state;
    const rows = items.map(item => {
      const sizeMB = (item.filesize / (1024 * 1024)).toFixed(2);
      const dur = item.duration ? `${item.duration.toFixed(1)}s` : '';
      return `
        <div class="mqvm-row" data-id="${item.id}">
          <div class="mqvm-row-meta">
            <div class="mqvm-title">#${item.id} ${item.title || ''}</div>
            <div class="mqvm-sub">Size: ${sizeMB}MB ${dur ? ' · Duration: ' + dur : ''}</div>
          </div>
          <div class="mqvm-row-actions">
            ${item.url ? `<a class="button" href="${item.url}" target="_blank" rel="noopener">View</a>` : ''}
            <button class="button mqvm-replace" data-id="${item.id}">Replace</button>
            <button class="button mqvm-delete" data-id="${item.id}">Delete</button>
          </div>
        </div>
      `;
    }).join('');

    const quota = `
      <div class="mqvm-quota">
        <div>Plan: max videos ${limits.max_videos}, total ${limits.max_total_mb}MB (file max ${max_file_mb}MB)</div>
        <div>Used: ${usage.count} videos, ${usage.total_mb}MB</div>
      </div>

      <h3 class="mqvm-row-title">Uploaded videos</h3>
    `;

    listEl.innerHTML = quota + (rows || '<p>No videos yet.</p>');

    listEl.querySelectorAll('.mqvm-replace').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-id');
        const f = inputFile.files && inputFile.files[0];
        if (!f) { alert('Choose a video in the file input first, then click Replace.'); return; }
        uploadFile(f, id);
      });
    });

    listEl.querySelectorAll('.mqvm-delete').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.getAttribute('data-id');
        if (!confirm('Delete this video?')) return;
        const res = await api(`/delete/${id}`, { method: 'DELETE' });
        if (!res.ok) alert('Delete failed: ' + (await res.text()));
        else await refresh();
      });
    });
  }

  async function uploadFile(file, replaceId = null, durationSec = null) {
    if (!file) return;
    if (file.size > MAX_FILE_BYTES) { alert('File exceeds 300MB limit.'); return; }

    const fd = new FormData();
    fd.append('file', file, file.name || 'video.webm');
    if (replaceId) fd.append('replace_id', String(replaceId));
    if (durationSec != null) fd.append('duration', String(durationSec));

    setProgress(10);
    const res = await api('/upload', { method: 'POST', body: fd });
    setProgress(80);

    if (!res.ok) {
      alert('Upload failed: ' + (await res.text()));
      setProgress(0);
      return;
    }
    await refresh();
    setProgress(100);
  }

  // Manual file upload
  btnUpload.addEventListener('click', async () => {
    const file = inputFile.files && inputFile.files[0];
    if (!file) { alert('Choose a video file first.'); return; }
    await uploadFile(file);
  });

  // Recording flow (same-frame preview + HUD + confirm)
  btnRecord.addEventListener('click', async () => {
    if (!window.isSecureContext && location.hostname !== 'localhost') { alert('Recording requires HTTPS (or localhost).'); return; }
    if (!navigator.mediaDevices?.getUserMedia) { alert('Recording not supported.'); return; }
    if (typeof MediaRecorder === 'undefined') { alert('MediaRecorder API not available.'); return; }

    // MIME selection
    const candidates = ['video/webm;codecs=vp9,opus','video/webm;codecs=vp8,opus','video/webm'];
    recordedMime = 'video/webm';
    for (const c of candidates) if (MediaRecorder.isTypeSupported?.(c)) { recordedMime = c; break; }

    // Get stream
    try {
      liveStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: true
      });
    } catch (e) { alert('Cannot access camera/mic. Check permissions.'); return; }

    // Live preview
    liveVideo.srcObject = liveStream;
    liveVideo.muted = true;
    liveVideo.controls = false;
    overlay.hidden = false;
    confirmRow.hidden = true;

    recordedChunks = [];
    recordedBlob = null;
    totalBytes = 0;
    startTs = performance.now();
    timerEl.textContent = '00:00';
    sizeEl.textContent  = '0.00 MB';

    try {
      mediaRecorder = new MediaRecorder(liveStream, { mimeType: recordedMime });
    } catch (e) { alert('Recorder cannot start with available codecs.'); stopLiveStream(); return; }

    mediaRecorder.ondataavailable = (e) => {
      if (e.data && e.data.size > 0) {
        recordedChunks.push(e.data);
        totalBytes += e.data.size;
        sizeEl.textContent = (totalBytes / (1024*1024)).toFixed(2) + ' MB';
      }
    };

    mediaRecorder.onstop = async () => {
      stopTick();
      stopLiveStream(); // hides overlay & clears srcObject

      const blob = new Blob(recordedChunks, { type: recordedMime || 'video/webm' });
      if (blob.size > MAX_FILE_BYTES) { recordedChunks = []; alert('Recorded file exceeds 300MB. Retake a shorter clip.'); return; }

      const durationSec   = await estimateBlobDuration(blob);
      const finalDuration = Math.min(durationSec, MAX_SECONDS);
      if (finalDuration > MAX_SECONDS + 0.5) { recordedChunks = []; alert('Recorded video exceeds 60s. Please retake.'); return; }

      recordedBlob = blob;

      if (playbackURL) { URL.revokeObjectURL(playbackURL); playbackURL = null; }
      playbackURL = URL.createObjectURL(blob);

      // Same-frame playback
      liveVideo.srcObject = null;
      liveVideo.muted     = false;
      liveVideo.controls  = true;
      liveVideo.src       = playbackURL;
      liveVideo.play?.();

      metaEl.textContent = `Duration ~ ${finalDuration.toFixed(1)}s · Size ${(blob.size / (1024*1024)).toFixed(2)} MB`;
      confirmRow.hidden  = false;
      confirmRow.dataset.durationSec = String(finalDuration);
      liveHint.textContent = 'Preview ready. You can review, use, or retake.';
    };

    mediaRecorder.start(100);
    btnRecord.disabled = true;
    btnStop.disabled   = false;
    liveHint.textContent = 'Recording… Max 60s.';
    startTick();
    recordTimeout = setTimeout(() => safeStopRecording(), MAX_SECONDS * 1000);
  });

  btnStop.addEventListener('click', safeStopRecording);

  function safeStopRecording() {
    if (recordTimeout) clearTimeout(recordTimeout);
    if (mediaRecorder && mediaRecorder.state === 'recording') mediaRecorder.stop();
    btnRecord.disabled = false;
    btnStop.disabled   = true;
    confirmRow.style.display = 'flex';

  }

  function stopLiveStream() {
    overlay.hidden = true;
    if (liveStream) { liveStream.getTracks().forEach(t => t.stop()); liveStream = null; }
    liveVideo.srcObject = null;
  }

  function startTick() {
    stopTick();
    const tick = () => {
      const elapsed = (performance.now() - startTs) / 1000;
      timerEl.textContent = fmtTime(elapsed);
      tickHandle = requestAnimationFrame(tick);
    };
    tickHandle = requestAnimationFrame(tick);
  }
  function stopTick() {
    if (tickHandle) cancelAnimationFrame(tickHandle);
    tickHandle = null;
  }

  // Confirm actions
  btnUse.addEventListener('click', async () => {
    if (!recordedBlob) return;
    const durationSec = Number(confirmRow.dataset.durationSec || '0') || null;
    const file = new File([recordedBlob], `recording-${Date.now()}.webm`, { type: recordedMime || 'video/webm' });
    await uploadFile(file, null, durationSec);
    cleanupPlayback();
  });

  btnRetake.addEventListener('click', () => {
    cleanupPlayback();
    // optionally auto-trigger a new recording: btnRecord.click();
  });

  function cleanupPlayback() {
    confirmRow.hidden = true;
    recordedChunks = [];
    recordedBlob   = null;
    totalBytes     = 0;
    if (playbackURL) { URL.revokeObjectURL(playbackURL); playbackURL = null; }
    liveVideo.pause?.();
    liveVideo.removeAttribute('src');
    liveVideo.load?.();
    liveVideo.controls = false;
    liveVideo.muted    = true;
    liveHint.textContent = 'Align yourself in the frame. Preview is muted; audio still records.';
  }

  // Estimate duration via <video> metadata
  function estimateBlobDuration(blob) {
    return new Promise((resolve) => {
      const v = document.createElement('video');
      v.preload = 'metadata';
      v.onloadedmetadata = () => { const d = isFinite(v.duration) ? v.duration : MAX_SECONDS; URL.revokeObjectURL(v.src); resolve(d); };
      v.onerror = () => resolve(MAX_SECONDS);
      v.src = URL.createObjectURL(blob);
    });
  }

  refresh();
})();
