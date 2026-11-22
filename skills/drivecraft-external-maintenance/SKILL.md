---
name: drivecraft-external-maintenance
description: Review Drivecraft's pure YouTube adapter for search, metadata, video and single-track audio downloads, using supplied diagnostics and bounded repair contracts.
---

# Drivecraft external maintenance

The application supplies the current source, yt-dlp version and bounded diagnostics. Repair demonstrated provider schema/format incompatibilities only. A timeout, CAPTCHA, unavailable item, missing dependency, authentication requirement or provider rate limit is not evidence that changing code will help. Report those conditions without inventing a fix or claiming a live test you did not perform.

Only `dc_youtube_adapter_dcft.php` is editable. Preserve the file outside the four existing function bodies, including their signatures and order:

- `dcmtAdapterSearch`: at most four unique results, canonical 11-character YouTube IDs and URLs.
- `dcmtAdapterInspect`: one item's identity, title, description, duration and live status.
- `dcmtAdapterFormat`: bounded, browser-compatible H.264/AAC MP4 video at the selected maximum height.
- `dcmtAdapterAudioFormat`: an audio-only yt-dlp selector for one item. Preserve `bestaudio` semantics; never introduce video merging, external URLs, playlists or searches here.

All four functions are pure: no filesystem, network, processes, credentials, superglobals, dynamic execution, includes, new functions or classes. Preserve empty/error distinctions and caller contracts.

Audio downloads use the same durable queue as video, with `media_kind=audio`. The protected `dc_youtube_audio_dcft.php` handles conversion and publication: Opus is the default; MP3 and AAC/M4A are available, with selectable bitrate ceilings. There is no HLS ladder and no whole-album import. The selected bitrate cannot restore source quality. Keep one item per task, progress rings, minimization, retries, quota, owner checks, musical-library ancestry and provenance intact. Validate output codec, duration and absence of video before publication; an FFmpeg or storage failure belongs to the protected pipeline and must be reported rather than repaired in the adapter.

Contract probes cover search, metadata, video and audio format selection. Diagnostics explicitly distinguish static contract results from live downloads; do not report audio download or conversion as live-tested when diagnostics say `not_live_tested`. A required yt-dlp update, encoder installation or protected invocation change must be reported for administrator action.

Protected modules own HTTP/3, URL allowlists, subprocess deadlines, byte limits, quota, durable jobs/retries, authentication, progress, conversion, publication, configuration and billing. Do not change them.

Return one JSON object without Markdown fences:

`{"summary":"Explanation in Spanish with evidence and limitations","files":{"dc_youtube_adapter_dcft.php":"<?php\n...complete source..."}}`

Return `files: {}` when no justified repair exists. Proposals must pass trusted syntax, pure-body and isolated contract validators before configured automatic publication. The host backs up the current version and checks concurrent edits. Never alter a validator, this skill, diagnostics or settings to make a proposal pass. Token usage comes exclusively from provider response metadata; never invent costs, balance or usage counts.
