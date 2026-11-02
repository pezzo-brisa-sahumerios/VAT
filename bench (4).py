# Benchmarks externos (nunca usados en entrenamiento): parafraseo GPT-4 (MAGE OOD), GPT-4 sin parafraseo, HVSIA español (Qwen2.5 vs Wikipedia ES)
import sys, json, pandas as pd, numpy as np
sys.argv=[sys.argv[0]]+sys.argv[1:]
import pyinfer
if len(sys.argv)>1: pyinfer.M=json.load(open(sys.argv[1])); pyinfer.idx={t:i for i,t in enumerate(pyinfer.M['vocab'])}; pyinfer.F=len(pyinfer.M['familias'])
M=pyinfer.M; ta,th=M['umbrales']['ia'],M['umbrales']['humano']
D='/home/ubuntu/data/'
def mage(f):  # En MAGE label 1 = humano, 0 = máquina: se invierte a la convención de VAT (1 = IA)
    d=pd.read_csv(D+f); d['label']=1-d.label; return d
sets={'MAGE OOD GPT-4 parafraseado':mage('mage_ood_para.csv'),'MAGE OOD GPT-4':mage('mage_ood_gpt.csv'),
      'HVSIA español (Qwen2.5 vs Wikipedia)':pd.read_parquet(D+'hvsia.parquet')}
out={}
for k,d in sets.items():
    d=d[d.text.str.split().str.len()>=40]
    p=np.array([pyinfer.infer(t)[0] for t in d.text]); y=d.label.values
    hum=y==0
    r=dict(n=len(d),fpr=float((p[hum]>=ta).mean()),tpr=float((p[~hum]>=ta).mean()),nc=float(((p>th)&(p<ta)).mean()))
    out[k]=r; print(f"{k:42s} n={r['n']} FP={r['fpr']:.3%} TP={r['tpr']:.3%} NC={r['nc']:.3%}")
json.dump(out,open(sys.argv[2] if len(sys.argv)>2 else 'bench_out.json','w'))
