# Guarda probabilidades de un modelo (JSON exportado) en: prueba v2 + MAGE OOD + HVSIA, para comparar antes/después a igual tasa de FP
import sys, json, numpy as np, pandas as pd, pyinfer
M=json.load(open(sys.argv[1])); pyinfer.M=M; pyinfer.idx={t:i for i,t in enumerate(M['vocab'])}; pyinfer.F=len(M['familias'])
D='/home/ubuntu/data/'
df=pd.read_json('corpus2.jsonl',lines=True); te=np.load('split_te2.npy'); d=df.iloc[te][['text','label','lang','source']].copy(); d['set']='test'
def mage(f,n):
    x=pd.read_csv(D+f); x['label']=1-x.label; x['lang']='en'; x['source']=n; x['set']=n; return x[['text','label','lang','source','set']]
h=pd.read_parquet(D+'hvsia.parquet'); h['lang']='es'; h['source']='hvsia'; h['set']='hvsia'
A=pd.concat([d,mage('mage_ood_para.csv','mage_para'),mage('mage_ood_gpt.csv','mage_gpt'),h[['text','label','lang','source','set']]],ignore_index=True)
A['nw']=A.text.str.split().str.len(); A=A[A.nw>=40].reset_index(drop=True)
A['p']=[pyinfer.infer(t)[0] for t in A.text]
A.drop(columns='text').to_parquet(sys.argv[2])
