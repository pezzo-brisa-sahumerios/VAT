import pandas as pd, json, random, re, collections
random.seed(7)
D='/home/ubuntu/data/'
rows=[]
def add(text,label,fam,src,lang):
    if not isinstance(text,str): return
    t=text.strip()
    if len(t.split())<25: return
    rows.append(dict(text=t,label=label,family=fam,source=src,lang=lang))
def samp(df,n): return df.sample(min(n,len(df)),random_state=7)
# IberAuTexTification 2024
s=pd.read_csv(D+'iber_s1_train.tsv',sep='\t')
mp={'A':'meta','B':'cohere','C':'otros','D':'openai','E':'mistral','F':'openai'}
mn={'A':'llama2-70b','B':'cohere-command','C':'ai21-j2','D':'gpt-3.5-instruct','E':'mixtral-8x7b','F':'gpt-4'}
for lang,nh,ng in [('es',12000,12000),('en',12000,14000)]:
    x=s[s.language==lang]
    for _,r in samp(x[x.model=='human'],nh).iterrows(): add(r.text,0,'human','iber-human-'+r.domain,lang)
    for _,r in samp(x[x.model!='human'],ng).iterrows(): add(r.text,1,mp[r.model],'iber-'+mn[r.model],lang)
# AuTexTification 2023
for lang in ['es','en']:
    a=pd.read_csv(D+f'aut_train_subtask_1_{lang}_train.tsv',sep='\t')
    for _,r in samp(a[a.label=='human'],6000 if lang=='es' else 4000).iterrows(): add(r.text,0,'human','aut23-human',lang)
    for _,r in samp(a[a.label=='generated'],6000 if lang=='es' else 4000).iterrows(): add(r.text,1,'legacy','aut23-gen',lang)
# artnitolog modern parallel
ar=pd.read_parquet(D+'artn.parquet')
cols={'human':('human',0,3000),'GPT4 Turbo 2024-04-09':('openai',1,1500),'GPT4 Omni':('openai',1,1500),'Claude 3 Opus':('anthropic',1,3000),
      'Llama3 70B':('meta',1,1500),'Command R+':('cohere',1,1500),'YandexGPT 3 Pro':('otros',1,600),'GigaChat Pro':('otros',1,600)}
for c,(fam,lab,n) in cols.items():
    for _,r in samp(ar,n).iterrows(): add(r[c],lab,fam,'artn-'+c.split(' 20')[0].replace(' ','-').lower()+'-'+r.dataset_name,'en')
# SES academic
def lines(f,n):
    L=[l.strip() for l in open(D+f,errors='ignore').read().split('\n')[:-1] if len(l.split())>40]
    random.shuffle(L); return L[:n]
for l in lines('ses_human.txt',2500): add(l,0,'human','ses-arxiv','en')
for l in lines('ses_gemini.txt',2500): add(l,1,'google','ses-gemini-2.0-flash','en')
for l in lines('ses_gpt35.txt',1200): add(l,1,'openai','ses-gpt-3.5','en')
# textsight 2026
for f,lab in [('ts_wiki.jsonl',0),('ts_lit.jsonl',0),('ts_pmc.jsonl',0),('ts_ai.jsonl',1)]:
    for l in open(D+f):
        j=json.loads(l); src=j.get('source') or f
        fam='human' if lab==0 else ('anthropic' if 'claude' in src else 'openai' if 'gpt' in src else 'otros')
        add(j['text'],lab,fam,'ts-'+src,'en')
# Claude Sonnet 4.6
c=pd.read_parquet(D+'claude46.parquet')
txt=pd.concat([c.raw_ab,c.raw_ba]).drop_duplicates()
txt=txt.sample(1500,random_state=7)
for t in txt:
    t=t.strip().rsplit('\n',1)[0]; add(t,1,'anthropic','claude-sonnet-4.6','en')
# danibor spanish wiki vs llama3.2
raw=open(D+'danibor.jsonl').read(); dec=json.JSONDecoder(); db=[]; i=0
while i<len(raw):
    while i<len(raw) and raw[i] in ' \n\r\t,[]': i+=1
    if i>=len(raw): break
    o,i=dec.raw_decode(raw,i); db.append(o)
random.shuffle(db)
for j in db[:9000]:
    h=j['text']; k=max(h.rfind('. '),h.rfind('.\n'))
    if k>0: h=h[:k+1]
    add(h,0,'human','danibor-wiki-es','es')
for j in db[9000:17000]: add(j['llama3.2'],1,'meta','danibor-llama3.2','es')
# small gemini chat
for l in open(D+'gem_small.jsonl'):
    for m in json.loads(l)['messages']:
        if m['role']=='assistant': add(m['content'],1,'google','gemini-chat','en')
# COLING-2025 MGT (M4GT + MAGE + HC3): modelos de chat modernos y humanos de dominios variados
import pyarrow.parquet as pq
cg=pq.read_table(D+'coling_en_dev.parquet').to_pandas()
FAMC={'gpt-3.5-turbo':'openai','gpt-35':'openai','gpt4':'openai','gpt4o':'openai','text-davinci-003':'openai',
      'llama3-8b':'meta','llama3-70b':'meta','gemma-7b-it':'google','gemma2-9b-it':'google','mixtral-8x7b':'mistral','cohere':'cohere'}
NC={'gpt4o':3500,'gpt4':3000,'gemma2-9b-it':3000,'gemma-7b-it':2500,'llama3-70b':3000,'llama3-8b':2000,'mixtral-8x7b':3000,
    'cohere':2500,'gpt-3.5-turbo':3000,'gpt-35':1500,'text-davinci-003':1000}
for m,n in NC.items():
    for _,r in samp(cg[cg.model==m],n).iterrows(): add(r.text,1,FAMC[m],'coling-'+m+'-'+r.sub_source,'en')
hu=cg[cg.model=='human']
for ss,g in hu.groupby('sub_source'):
    for _,r in samp(g,1100).iterrows(): add(r.text,0,'human','coling-human-'+ss,'en')
del cg
# RAID (abstracts): modelos de chat con ataques adversariales (parafraseo, sinónimos, faltas…) y humanos con los mismos ataques
import csv; csv.field_size_limit(10**9)
ATK={'none','paraphrase','synonym','perplexity_misspelling','article_deletion','alternative_spelling','insert_paragraphs'}
FAMR={'chatgpt':'openai','gpt4':'openai','llama-chat':'meta','mistral-chat':'mistral','cohere-chat':'cohere'}
buck=collections.defaultdict(list)
with open(D+'raid_part.csv',newline='') as f:
    try:
        for row in csv.DictReader(f):
            if row['attack'] in ATK and (row['model'] in FAMR or row['model']=='human'): buck[(row['model'],row['attack'])].append(row['generation'])
    except Exception: pass
for (m,a),L in buck.items():
    random.shuffle(L)
    for t in L[:(700 if m=='human' else 260)]: add(t,0 if m=='human' else 1,'human' if m=='human' else FAMR[m],'raid-'+m+'-'+a,'en')
del buck
df=pd.DataFrame(rows).drop_duplicates('text').reset_index(drop=True)
print(len(df)); print(df.groupby(['lang','label']).size()); print(df.family.value_counts())
df.to_json('/home/ubuntu/vat_train/corpus2.jsonl',orient='records',lines=True,force_ascii=False)

# Rasgos estilométricos densos (paralelo en 2 núcleos)
import numpy as np, multiprocessing as mp
from features import dense, norm
def _d(t): return dense(norm(t))
with mp.Pool(2) as pool: X=pool.map(_d, df.text.tolist(), chunksize=500)
np.save('/home/ubuntu/vat_train/dense2.npy', np.array(X)); print('dense ok', len(X))
