import pickle, json, numpy as np, datetime
from features import DENSE
m=pickle.load(open('model_v6.pkl','rb')); M=json.load(open('metrics_v6.json'))
V=len(m['vocab']); F=len(m['fams'])
import pandas as pd
_df=pd.read_json('corpus2.jsonl',lines=True); _X=np.load('dense2.npy')
MH=[round(float(x),5) for x in _X[_df.label.values==0].mean(0)]
MI=[round(float(x),5) for x in _X[_df.label.values==1].mean(0)]
q=lambda a: [int(round(x*1000)) for x in a]
fco=m['fcoef']  # F x (V+24)
out=dict(nombre='VAT Forensic Model', version='2.0.0', generado=datetime.date.today().isoformat(),
  escala=1000, vocab=m['vocab'], wb=q(m['coef'][:V]), wf=q(fco[:,:V].T.ravel()),
  densos=dict(nombres=DENSE, media_humano=MH, media_ia=MI, media=[round(float(x),6) for x in m['mu']], desv=[round(float(x),6) for x in m['sd']],
              wb=q(m['coef'][V:]), wf=q(fco[:,V:].T.ravel())),
  sesgo=round(float(m['b']),5), familias=m['fams'], sesgo_fam=[round(float(x),5) for x in m['fb']],
  platt=dict(a=round(float(m['A']),6), b=round(float(m['B']),6)),
  umbrales=dict(ia=round(m['t_ai'],4), humano=round(m['t_h'],4), min_palabras=40),
  metricas={'auc':round(M['auc'],4),'familia_acc':round(M['famacc'],4),
     'global':M['R']['global'],'es':M['R']['lang_es'],'en':M['R']['lang_en'],
     'por_longitud':{k:v for k,v in M['R'].items() if k.startswith('len_')}})
s=json.dumps(out,ensure_ascii=False,separators=(',',':'))
open('/home/ubuntu/VAT-Detector/modelo/vat-modelo.json','w').write(s)
print(len(s)/1e6,'MB', V, F, m['fams'])
open('/home/ubuntu/VAT-Detector/modelo/vat-modelo.js','w').write(
 '/* VAT - Modelo forense calibrado (generado automáticamente por entrenamiento/export.py; NO editar a mano).\n'
 ' * Este archivo existe para que el motor JavaScript funcione incluso abriendo index.html sin servidor (file://),\n'
 ' * donde fetch() de un .json está bloqueado por el navegador. Si se borra, el modo offline no tiene modelo. */\n'
 'window.VAT_MODELO=' + s + ';\n')
