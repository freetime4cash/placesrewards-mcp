import base64, json, pathlib, subprocess, urllib.request, urllib.error, sys, time

ROOT=pathlib.Path(r"C:\Projects\placesrewards-mcp")
TOKEN=(ROOT/"tc-inspect-token.txt").read_text(encoding="utf-8").strip()
BRIDGE_SECRET=(ROOT/"bridge-secret.txt").read_text(encoding="utf-8").strip()

DIR_HEAD={
    "X-PR-Inspect":TOKEN,
    "Content-Type":"application/json",
    "User-Agent":"PlacesRewardsAutopilot/2.0"
}
APP_HEAD={
    "X-PR-DEMO-BRIDGE":BRIDGE_SECRET,
    "Content-Type":"application/json",
    "User-Agent":"PlacesRewardsAutopilot/2.0"
}

def get_json(url, headers=None):
    req=urllib.request.Request(url,headers=headers or DIR_HEAD,method="GET")
    with urllib.request.urlopen(req,timeout=60) as r:
        return json.loads(r.read().decode())

def post_json(url,payload,headers=None,timeout=90):
    req=urllib.request.Request(
        url,
        data=json.dumps(payload).encode(),
        headers=headers or DIR_HEAD,
        method="POST"
    )
    with urllib.request.urlopen(req,timeout=timeout) as r:
        return json.loads(r.read().decode())

def app_post(path,payload,timeout=90):
    return post_json("https://app.placesrewards.com"+path,payload,APP_HEAD,timeout)

def deploy_png(slug,kind,path):
    raw=path.read_bytes()
    if not raw.startswith(b"\x89PNG\r\n\x1a\n"):
        raise RuntimeError(f"{kind} asset is not PNG")
    return app_post("/api/demo/internal/asset-deploy",{
        "slug":slug,
        "kind":kind,
        "data_base64":base64.b64encode(raw).decode("ascii"),
    },timeout=120)

def enrich_manifest(m,slug,selfie_url,insight_url,native):
    m["native"]=native
    for x in m.get("sequence",[]):
        if x.get("kind")=="totalcontest_selfie":
            x["screenshot"]=selfie_url
        elif x.get("kind")=="totalcontest_insight":
            x["screenshot"]=insight_url
        simple={
            "snapshot":"We explain what we know about the business and what still needs to be verified.",
            "enrollment":"A QR code or join link turns an anonymous visitor into a permissioned Places Rewards member.",
            "loyalty":"A native stamp card gives the customer a reason to return instead of making only one visit.",
            "scratch":"A native mystery-reward game adds fun and another reason to engage.",
            "referral":"A native referral campaign turns happy customers into trackable word-of-mouth growth.",
            "voucher":"A native targeted comeback offer can be prepared for a useful time without activating a production benefit.",
            "analytics":"The owner sees real counters from the isolated demo workspace, not invented production results.",
            "plan":"The demo ends by showing the owner which Places Rewards plan fits and why."
        }
        if x.get("kind") in simple:
            x["simple_explanation"]=simple[x["kind"]]
    return m

def process(prospect_id):
    built=post_json(
        "https://dir.placesrewards.com/wp-json/places-rewards/v1/autopilot/demo/build",
        {"prospect_id":prospect_id},
        DIR_HEAD,
    )
    if not built.get("ok"):
        raise RuntimeError("directory demo build failed")

    m=built["manifest"]
    slug=m["slug"]
    business=m.get("business") or slug
    work=ROOT/"autopilot-demos"/slug
    work.mkdir(parents=True,exist_ok=True)

    raw=work/"manifest-source.json"
    raw.write_text(json.dumps({"manifest":m},indent=2),encoding="utf-8")

    subprocess.run(
        ["node",str(ROOT/"capture_business_demo.mjs"),str(raw),str(work)],
        check=True,
        timeout=180,
    )

    native_result=app_post("/api/demo/internal/native-build",{
        "business":business,
        "slug":slug,
        "category":m.get("category",""),
    },timeout=120)
    if not native_result.get("ok"):
        raise RuntimeError("native asset build failed")
    native=native_result["native"]

    selfie=deploy_png(slug,"selfie",work/"selfie.png")
    insight=deploy_png(slug,"insight",work/"insight.png")
    if not selfie.get("ok") or not insight.get("ok"):
        raise RuntimeError("demo screenshot deployment failed")

    m=enrich_manifest(
        m,
        slug,
        selfie["asset_url"],
        insight["asset_url"],
        native,
    )
    final=work/f"demo-{slug}.json"
    final.write_text(json.dumps(m,indent=2),encoding="utf-8")

    deployed=app_post("/api/demo/internal/manifest-deploy",{"manifest":m},timeout=90)
    if not deployed.get("ok"):
        raise RuntimeError("manifest deployment failed")

    demo_url=deployed.get("demo_url") or f"https://app.placesrewards.com/demo/business/{slug}"
    check_req=urllib.request.Request(
        demo_url,
        headers={"User-Agent":"Mozilla/5.0 PlacesRewardsAutopilot/2.0"}
    )
    with urllib.request.urlopen(check_req,timeout=60) as check:
        if check.status!=200:
            raise RuntimeError("Laravel demo returned "+str(check.status))

    marked=post_json(
        "https://dir.placesrewards.com/wp-json/places-rewards/v1/autopilot/demo/deployed",
        {"prospect_id":prospect_id,"demo_url":demo_url},
        DIR_HEAD,
    )
    print("DEPLOYED",prospect_id,business,demo_url,marked.get("status"))

def main():
    limit=3
    if "--limit" in sys.argv:
        i=sys.argv.index("--limit")
        limit=max(1,min(10,int(sys.argv[i+1])))

    q=get_json(
        f"https://dir.placesrewards.com/wp-json/places-rewards/v1/autopilot/queue?limit={limit}",
        DIR_HEAD,
    )
    rows=q.get("queue",[])
    print("QUEUE",len(rows),[(x["prospect_id"],x["business"],x["score"],x["status"]) for x in rows])
    for row in rows:
        try:
            process(int(row["prospect_id"]))
        except Exception as e:
            print("FAILED",row["prospect_id"],row["business"],repr(e))

if __name__=="__main__":
    main()
