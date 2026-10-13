"""Supported GD 2.2081 x64 gate. Patches imports, entry point and MenuLayer init."""
import hashlib, struct
from pathlib import Path
MENU_RVA=0x333a90
MENU_HEAD=bytes.fromhex('488bc448894808')
SECTION=b'.mucho\0\0'
def align(n,a): return (n+a-1)//a*a
def u16(b,n): return struct.unpack_from('<H',b,n)[0]
def u32(b,n): return struct.unpack_from('<I',b,n)[0]
def put(b,n,v): struct.pack_into('<I',b,n,v)
def inspect(b):
    if b[:2]!=b'MZ': raise ValueError('Нужен EXE Geometry Dash 2.2081 x64.')
    pe=u32(b,0x3c)
    if b[pe:pe+4]!=b'PE\0\0' or u16(b,pe+4)!=0x8664: raise ValueError('Поддерживается Windows x64.')
    opt=pe+24
    if u16(b,opt)!=0x20b: raise ValueError('Неверный PE64.')
    count=u16(b,pe+6);table=opt+u16(b,pe+20)
    if table+(count+1)*40>u32(b,opt+60): raise ValueError('Нет места в таблице PE-секций.')
    sections=[]
    for n in range(count):
        at=table+n*40;size,rva,raw_size,raw=struct.unpack_from('<IIII',b,at+8)
        if raw+raw_size>len(b): raise ValueError('Повреждённые секции EXE.')
        sections.append((b[at:at+8],size,rva,raw_size,raw))
    return pe,opt,table,sections

def rva_offset(sections,rva):
    for _,size,va,raw_size,raw in sections:
        if va<=rva<va+raw_size: return raw+rva-va
    raise ValueError('Неизвестный адрес PE.')

def gated(b):
    return any(s[0]==SECTION for s in inspect(b)[3])

def patch(original,expected_text_hash):
    pe,opt,table,sections=inspect(original)
    if any(s[0]==SECTION for s in sections): raise ValueError('EXE уже содержит MuchoGate.')
    text=next(s for s in sections if s[0].rstrip(b'\0')==b'.text')
    if hashlib.sha256(original[text[4]:text[4]+text[3]]).hexdigest()!=expected_text_hash:
        raise ValueError('Код EXE не совпадает с поддерживаемой GD 2.2081. Оригинал не изменён.')
    menu_at=rva_offset(sections,MENU_RVA)
    if original[menu_at:menu_at+7]!=MENU_HEAD: raise ValueError('Неподдерживаемая функция меню.')
    b=bytearray(original);file_align=u32(b,opt+36);section_align=u32(b,opt+32)
    raw=align(len(b),file_align);rva=align(max(s[2]+max(s[1],s[3]) for s in sections),section_align)
    old_import=u32(b,opt+120);offset=rva_offset(sections,old_import);descs=[]
    while b[offset:offset+20]!=bytes(20):
        if len(descs)>1000: raise ValueError('Некорректные импорты.')
        descs.append(bytes(b[offset:offset+20]));offset+=20
    data=bytearray(b''.join(descs)+bytes(40))
    def add(value,alignment=1):
        data.extend(bytes(align(len(data),alignment)-len(data)));at=len(data);data.extend(value);return at
    dll=add(b'MuchoBootstrap.dll\0')
    names=[add(b'\0\0'+name+b'\0',2) for name in [b'MuchoBootstrapStart',b'MuchoClientRequire']]
    ilt=add(struct.pack('<QQQ',*(rva+n for n in names),0),8)
    iat=add(struct.pack('<QQQ',*(rva+n for n in names),0),8)
    struct.pack_into('<IIIII',data,len(descs)*20,rva+ilt,0,0,rva+dll,rva+iat)
    def call_iat(stub_start,code,iat_slot):
        insn_rva=rva+stub_start+len(code);code.extend(b'\xff\x15'+struct.pack('<i',rva+iat+iat_slot-insn_rva-6))
    ep=u32(b,opt+16);entry=align(len(data),16);data.extend(bytes(entry-len(data)))
    code=bytearray(b'\x48\x83\xec\x28');call_iat(entry,code,0);code.extend(b'\x48\x83\xc4\x28')
    code.extend(b'\xe9'+struct.pack('<i',ep-(rva+entry+len(code)+5)));data.extend(code)
    menu=align(len(data),16);data.extend(bytes(menu-len(data)))
    code=bytearray(b'\x51\x48\x83\xec\x20');call_iat(menu,code,8);code.extend(b'\x48\x83\xc4\x20\x59'+MENU_HEAD)
    code.extend(b'\xe9'+struct.pack('<i',MENU_RVA+7-(rva+menu+len(code)+5)));data.extend(code)
    b[menu_at:menu_at+7]=b'\xe9'+struct.pack('<i',rva+menu-MENU_RVA-5)+b'\x90\x90'
    at=table+len(sections)*40;struct.pack_into('<8sIIIIIIHHI',b,at,SECTION,len(data),rva,align(len(data),file_align),raw,0,0,0,0,0xe0000060)
    struct.pack_into('<H',b,pe+6,len(sections)+1);put(b,opt+16,rva+entry);put(b,opt+56,align(rva+len(data),section_align))
    put(b,opt+120,rva);put(b,opt+124,(len(descs)+2)*20);put(b,opt+64,0)
    # Modified images cannot retain stale certificate/bound-import directories.
    for directory in (4,11): b[opt+112+directory*8:opt+120+directory*8]=bytes(8)
    b.extend(bytes(raw-len(b)));b.extend(data);b.extend(bytes(align(len(b),file_align)-len(b)))
    return bytes(b)
