"""Compact timeline of a Playwright trace: actions, errors, navigations, console
errors and matching network requests on one clock.

Usage:
    python3 _devextras/e2e/playwright-trace-timeline.py path/to/trace.zip
    python3 _devextras/e2e/playwright-trace-timeline.py path/to/trace.zip /api/v1/chats

The second argument filters requests by URL substring (default: /api/). An
unzipped trace directory works as well as the zip.

Action `startTime`/`endTime` and network `_monotonicTime` are both monotonic
milliseconds, so the rows are directly comparable. NET rows are stamped at
request start; the ms value after the status is the request duration.
"""
import fnmatch
import json
import os
import sys
import zipfile

# Browser-context traces only; test.trace (test runner steps) would repeat every action.
TRACE_PATTERNS = ('*-trace.trace', '*-trace.network')


def is_trace_file(name):
    return any(fnmatch.fnmatch(os.path.basename(name), pattern) for pattern in TRACE_PATTERNS)


def trace_files(source):
    """Yield (name, text) for every browser trace and network file in a zip or directory."""
    if zipfile.is_zipfile(source):
        with zipfile.ZipFile(source) as archive:
            for name in sorted(archive.namelist()):
                if is_trace_file(name):
                    yield name, archive.read(name).decode('utf-8', 'replace')
        return
    for name in sorted(os.listdir(source)):
        if is_trace_file(name):
            with open(os.path.join(source, name), encoding='utf-8', errors='replace') as fh:
                yield name, fh.read()


def records(text):
    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            yield json.loads(line)
        except json.JSONDecodeError:
            pass


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    url_filter = sys.argv[2] if len(sys.argv) > 2 else '/api/'
    events = []

    for name, text in trace_files(sys.argv[1]):
        for o in records(text):
            if name.endswith('.network'):
                snap = o.get('snapshot', {})
                url = snap.get('request', {}).get('url', '')
                if url_filter not in url:
                    continue
                status = snap.get('response', {}).get('status')
                method = snap.get('request', {}).get('method')
                short_url = url.split('//', 1)[-1].split('/', 1)[-1][:110]
                duration = int(snap.get('time', 0))
                events.append((snap.get('_monotonicTime', 0), 'NET', f'{method} {status} {duration}ms {short_url}'))
                continue
            kind = o.get('type')
            if kind == 'before':
                params = json.dumps(o.get('params', {}))[:160]
                events.append((o.get('startTime', 0), 'ACT', f"{o.get('class')}.{o.get('method')} {params}"))
            elif kind == 'after' and o.get('error'):
                events.append((o.get('endTime', 0), 'ERR', json.dumps(o['error'])[:220]))
            elif kind == 'console' and o.get('messageType') in ('error', 'warning'):
                events.append((o.get('time', 0), 'CON', f"{o.get('messageType')}: {o.get('text', '')[:200]}"))
            elif kind == 'event' and o.get('method') in ('navigated', 'pageError'):
                events.append((o.get('time', 0), 'EVT', json.dumps(o.get('params', {}))[:200]))

    events.sort(key=lambda e: e[0])
    start = events[0][0] if events else 0
    for ts, kind, text in events:
        print(f'{(ts - start) / 1000:8.2f}s {kind} {text}')


if __name__ == '__main__':
    main()
