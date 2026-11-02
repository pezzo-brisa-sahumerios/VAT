# VAT · entrenamiento v2. Uso: python3 train.py 60000 10 _v6 0.995  (K vocabulario, C regularización, sufijo, cuantil humano para el umbral IA)
# VAT_W=1 activa peso igual por fuente humana (probado: bajó AUC y la detección en español, por eso está apagado).
import pandas as pd, numpy as np, json, sys, scipy.sparse as sp
from sklearn.feature_extraction.text import CountVectorizer
from sklearn.feature_selection import chi2
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import train_test_split
from sklearn.metrics import roc_auc_score
from features import *
K=int(sys.argv[1]) if len(sys.argv)>1 else 20000
C=float(sys.argv[2]) if len(sys.argv)>2 else 4
SUF=sys.argv[3] if len(sys.argv)>3 else ''
Q=float(sys.argv[4]) if len(sys.argv)>4 else 0.995
import os
df=pd.read_json('corpus2.jsonl',lines=True); Xd=np.load('dense2.npy')
df['fam']=df.family.replace({'legacy':'otros'})
df['grp']=df.source.str.replace(r'-(essay|reuters|wp)$','',regex=True)
idx=np.arange(len(df))
tr,te=train_test_split(idx,test_size=0.3,random_state=1,stratify=df.grp)
va,te=train_test_split(te,test_size=0.5,random_state=1,stratify=df.grp.iloc[te])
texts=[norm(t) for t in df.text]
cv=CountVectorizer(analyzer=ngrams,min_df=5,lowercase=False)
Ctr=cv.fit_transform([texts[i] for i in tr]); terms=np.array(cv.get_feature_names_out())
y=df.label.values
s1,_=chi2(Ctr>0,y[tr]); ai=tr[y[tr]==1]
s2,_=chi2(cv.transform([texts[i] for i in ai])>0,df.fam.values[ai])
s1=np.nan_to_num(s1); s2=np.nan_to_num(s2)
sel=np.union1d(np.argsort(-s1)[:int(K*0.7)],np.argsort(-s2)[:int(K*0.5)])[:]
vocab=terms[sel]; print('vocab',len(vocab))
cv2=CountVectorizer(analyzer=ngrams,vocabulary=list(vocab),lowercase=False)
def sparse(ids):
    M=cv2.transform([texts[i] for i in ids]).astype(float); M.data=1+np.log(M.data)
    n=np.sqrt(np.asarray(M.multiply(M).sum(1))).ravel(); n[n==0]=1
    return sp.diags(1/n)@M
mu=Xd[tr].mean(0); sd=Xd[tr].std(0)+1e-9
def full(ids): return sp.hstack([sparse(ids),sp.csr_matrix(np.clip((Xd[ids]-mu)/sd,-5,5))]).tocsr()
Xtr,Xva,Xte=full(tr),full(va),full(te)
# Peso por muestra: cada fuente humana pesa lo mismo (evita que fuentes humanas difíciles y chicas, p.ej. ensayos de estudiantes, queden ignoradas)
gtr=df.grp.values[tr]; cnt=pd.Series(gtr).value_counts()
w=np.array([1/np.sqrt(cnt[g]) for g in gtr]); 
for c in (0,1):
    m=y[tr]==c; w[m]*=m.sum()/w[m].sum()*(len(tr)/2/m.sum())
if os.environ.get('VAT_W')!='1': w=np.where(y[tr]==1,len(tr)/2/(y[tr]==1).sum(),len(tr)/2/(y[tr]==0).sum())
clf=LogisticRegression(C=C,max_iter=3000,solver='liblinear').fit(Xtr,y[tr],sample_weight=w)
zva=clf.decision_function(Xva); zte=clf.decision_function(Xte)
pl=LogisticRegression(C=1e6).fit(zva.reshape(-1,1),y[va]); A,B=pl.coef_[0,0],pl.intercept_[0]
P=lambda z:1/(1+np.exp(-(A*z+B)))
pva,pte=P(zva),P(zte)
nw=np.array([len(WORD.findall(t)) for t in texts])
hv=pva[y[va]==0]; t_ai=float(np.quantile(hv,Q)); av=pva[y[va]==1]; t_h=float(np.quantile(av,0.03))
t_h=min(t_h,0.5); t_ai=max(t_ai,0.5)
print('AUC val',roc_auc_score(y[va],pva),'AUC test',roc_auc_score(y[te],pte),'A,B',A,B,'t_ai',t_ai,'t_h',t_h)
def rep(ids,p):
    yy=y[ids]; v=np.where(p>=t_ai,'ia',np.where(p<=t_h,'humano','nc'))
    hum=yy==0
    return dict(n=len(ids),fpr=float((v[hum]=='ia').mean()) if hum.any() else None,
        tpr=float((v[~hum]=='ia').mean()) if (~hum).any() else None,
        nc=float((v=='nc').mean()), acc_bin=float(((p>=0.5)==yy).mean()))
R={'global':rep(te,pte)}
g=df.grp.values[te]
for k in sorted(set(g)): R[k]=rep(te[g==k],pte[g==k])
for lo,hi in [(25,60),(60,120),(120,250),(250,10000)]:
    m=(nw[te]>=lo)&(nw[te]<hi); R[f'len_{lo}_{hi}']=rep(te[m],pte[m])
for l in ['es','en']:
    m=df.lang.values[te]==l; R['lang_'+l]=rep(te[m],pte[m])
for k,v in R.items(): print(f"{k:40s} n={v['n']:5d} FP={v['fpr']} TP={v['tpr']} NC={v['nc']:.3f} acc50={v['acc_bin']:.3f}")
# family model
fams=sorted(df.fam[df.label==1].unique())
atr=tr[y[tr]==1]; ate=te[y[te]==1]
fclf=LogisticRegression(C=C,max_iter=3000,class_weight='balanced').fit(Xtr[np.where(y[tr]==1)[0]],df.fam.values[atr])
pf=fclf.predict(Xte[np.where(y[te]==1)[0]])
print('family acc',(pf==df.fam.values[ate]).mean())
print(pd.crosstab(df.fam.values[ate],pf))
json.dump(dict(R=R,A=A,B=B,t_ai=t_ai,t_h=t_h,auc=roc_auc_score(y[te],pte),famacc=float((pf==df.fam.values[ate]).mean()),
  famcm=pd.crosstab(df.fam.values[ate],pf).to_dict()),open(f'metrics{SUF}.json','w'),default=float)
np.save(f'split_te{SUF}.npy',te)
import pickle; pickle.dump(dict(vocab=list(vocab),coef=clf.coef_[0],b=clf.intercept_[0],mu=mu,sd=sd,A=A,B=B,t_ai=t_ai,t_h=t_h,
  fams=list(fclf.classes_),fcoef=fclf.coef_,fb=fclf.intercept_),open(f'model{SUF}.pkl','wb'))
