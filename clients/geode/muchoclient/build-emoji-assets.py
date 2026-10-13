#!/usr/bin/env python3
"""Build the offline atlas and compact codepoint trie from pinned Twemoji assets."""
import argparse, hashlib, io, json, tarfile, urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent
CELL, TILE, COLS, PER_PAGE = 32, 28, 16, 256

def generate_index(source):
    assets=sorted(source['assets'], key=lambda a: a['name'])
    nodes=[{'children':{},'image':-1}]
    for image,asset in enumerate(assets):
        sequence=[int(part,16) for part in asset['name'].split('-') if part!='fe0f']
        node=0
        for cp in sequence:
            if cp not in nodes[node]['children']:
                nodes[node]['children'][cp]=len(nodes)
                nodes.append({'children':{},'image':-1})
            node=nodes[node]['children'][cp]
        assert nodes[node]['image'] in (-1,image),asset['name']
        nodes[node]['image']=image
    edges=[]; rows=[]
    for node in nodes:
        rows.append((len(edges),len(node['children']),node['image']))
        edges.extend(sorted(node['children'].items()))
    pages=(len(assets)+PER_PAGE-1)//PER_PAGE
    lines=['// Generated from pinned Twemoji 17.0.3. Do not edit.', '#pragma once',
           '#include "MuchoEmojiRules.hpp"', 'namespace mucho::emoji::data {',
           f'inline constexpr size_t ImageCount={len(assets)}, PageCount={pages};',
           f'inline constexpr unsigned Cell={CELL}, Tile={TILE}, Columns={COLS}, PerPage={PER_PAGE};',
           'inline constexpr TrieNode Nodes[] = {']
    lines.extend(f'{{{a},{b},{c}}},' for a,b,c in rows)
    lines+=['};','inline constexpr TrieEdge Edges[] = {']
    lines.extend(f'{{{a},{b}}},' for a,b in edges)
    lines+=['};','inline constexpr Catalog catalog(){ return {Nodes,Edges}; }','}']
    target=ROOT/'src/MuchoEmojiData.hpp'
    target.write_text('\n'.join(lines)+'\n',encoding='utf-8')
    return assets,pages

def build(source,archive):
    from PIL import Image
    assets,pages=generate_index(source)
    with tarfile.open(archive,'r:gz') as tar:
        members={m.name.split('/assets/72x72/',1)[-1]:m for m in tar.getmembers() if '/assets/72x72/' in m.name and m.name.endswith('.png')}
        assert set(members)=={a['name']+'.png' for a in assets},'Source asset list changed'
        sheets=[Image.new('RGBA',(COLS*CELL,COLS*CELL)) for _ in range(pages)]
        for index,asset in enumerate(assets):
            raw=tar.extractfile(members[asset['name']+'.png']).read()
            digest=hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
            assert digest==asset['sha'],f"Asset hash mismatch: {asset['name']}"
            icon=Image.open(io.BytesIO(raw)).convert('RGBA').resize((TILE,TILE),Image.Resampling.LANCZOS)
            cell=index%PER_PAGE
            sheets[index//PER_PAGE].paste(icon,((cell%COLS)*CELL+2,(cell//COLS)*CELL+2))
        out=ROOT/'resources'; out.mkdir(exist_ok=True)
        for page,sheet in enumerate(sheets): sheet.save(out/f'emoji-page-{page}.png',optimize=True)
    report={'version':source['version'],'commit':source['commit'],'images':len(assets),'pages':pages,
            'cell':CELL,'tile':TILE,'page_pixels':COLS*CELL,'max_texture_bytes':pages*(COLS*CELL)**2*4,
            'pages_sha256':[hashlib.sha256((ROOT/'resources'/f'emoji-page-{p}.png').read_bytes()).hexdigest() for p in range(pages)]}
    (ROOT/'resources/emoji-manifest.json').write_text(json.dumps(report,indent=2)+'\n')
    print('MUCHO_EMOJI_ASSETS_OK',json.dumps(report))

def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--index-only',action='store_true'); ap.add_argument('--archive',type=Path)
    args=ap.parse_args(); source=json.loads((ROOT/'emoji-source.json').read_text())
    if args.index_only:
        assets,pages=generate_index(source); print(f'MUCHO_EMOJI_INDEX_OK images={len(assets)} pages={pages}'); return
    archive=args.archive
    if archive is None:
        archive=ROOT/'twemoji-source.tar.gz'
        urllib.request.urlretrieve(f"https://codeload.github.com/jdecked/twemoji/tar.gz/{source['commit']}",archive)
    build(source,archive)

if __name__=='__main__': main()
