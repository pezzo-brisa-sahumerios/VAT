import re, math, unicodedata
WORD=re.compile(r'[^\W_]+')
TOK=re.compile(r'[^\W_]+|[^\w\s]|_')
SENT=re.compile(r'(?<=[.!?…])\s+|\n+')
DIG=re.compile(r'^[0-9]+$')
BUL=re.compile(r'^\s*([-*•]|\d+[.)])\s')
MD=re.compile(r'^\s*#{1,6}\s|\*\*')
STOP=set("""de la que el en y a los del se las por un para con no una su al lo como más pero sus le ya o este sí porque esta entre cuando muy sin sobre también me hasta hay donde quien desde todo nos durante todos uno les ni contra otros ese eso ante ellos e esto mí antes algunos qué unos yo otro otras otra él tanto esa estos mucho quienes nada muchos cual poco ella estar estas algunas algo nosotros es son fue ser ha han era
the of and to a in is that it was for on are as with his they be at one have this from by or had not but what all were when we there can an your which their if do will each about how up out them then she many some so these would other into has more her two like him see time could no make than first been its who now people my made over did down only way find use may long very after
""".split())
P1=set("yo me mi mis conmigo mío mía i my mine myself".split())
P2=set("tú tu tus te ti usted ustedes vos you your yours yourself".split())
DENSE=['sl_mean','sl_cv','mattr','wlen','long','comma','semi','colon','dash','paren','quote','quest','excl','ellip','digit','bullet','md','para','stop','p1','p2','contr','startrep','emoji']
def norm(t):
    t=unicodedata.normalize('NFC',t).replace('\r\n','\n').replace('\r','\n')
    return t
def tokens(t):
    t=re.sub(r'\n+',' ¶ ',t.lower())
    return ['<n>' if DIG.match(x) else x for x in TOK.findall(t)]
def ngrams(t):
    tk=tokens(t); out=list(tk)
    for i in range(len(tk)-1): out.append(tk[i]+' '+tk[i+1])
    return out
def is_emoji(ch):
    o=ord(ch); return 0x1F300<=o<=0x1FAFF or 0x2600<=o<=0x27BF
def dense(t):
    low=t.lower(); words=WORD.findall(low); n=max(len(words),1)
    sents=[s for s in (x.strip() for x in SENT.split(t)) if WORD.search(s)]
    sl=[len(WORD.findall(s)) for s in sents] or [n]
    m=sum(sl)/len(sl); sd=math.sqrt(sum((x-m)**2 for x in sl)/len(sl))
    if len(words)<50: mattr=len(set(words))/n
    else:
        vals=[len(set(words[i:i+50]))/50 for i in range(0,len(words)-49,10)]; mattr=sum(vals)/len(vals)
    lines=[l for l in t.split('\n') if l.strip()]; nl=max(len(lines),1)
    firsts=[WORD.findall(s.lower())[0] for s in sents]
    f={'sl_mean':min(m,80),'sl_cv':sd/m if m else 0,'mattr':mattr,
       'wlen':sum(len(w) for w in words)/n,'long':sum(len(w)>=7 for w in words)/n,
       'comma':t.count(',')/n,'semi':t.count(';')/n,'colon':t.count(':')/n,
       'dash':(t.count('—')+t.count('–'))/n,'paren':t.count('(')/n,
       'quote':sum(t.count(c) for c in '"“”«»')/n,'quest':t.count('?')/n,'excl':t.count('!')/n,
       'ellip':(t.count('…')+t.count('...'))/n,'digit':sum(any(c in '0123456789' for c in w) for w in words)/n,
       'bullet':sum(bool(BUL.match(l)) for l in lines)/nl,'md':sum(bool(MD.search(l)) for l in lines)/nl,
       'para':len(lines)/n*100,'stop':sum(w in STOP for w in words)/n,
       'p1':sum(w in P1 for w in words)/n,'p2':sum(w in P2 for w in words)/n,
       'contr':(t.count("'")+t.count('’'))/n,
       'startrep':(1-len(set(firsts))/len(firsts)) if len(firsts)>=2 else 0,
       'emoji':sum(is_emoji(c) for c in t)/n}
    return [f[k] for k in DENSE]
