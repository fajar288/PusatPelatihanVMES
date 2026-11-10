import { revalidateTag } from "next/cache";
import { NextResponse, type NextRequest } from "next/server";

/**
 * Webhook revalidasi cache CMS — dipanggil backend Laravel saat konten dipublikasikan.
 *   POST /api/revalidate  (header: x-revalidate-secret: <REVALIDATE_SECRET>)
 *   body JSON opsional: { "tags": ["posts", "post:slug"] }  → default ["cms"] (semua)
 */
export async function POST(req: NextRequest) {
  const secret = process.env.REVALIDATE_SECRET;
  const given = req.headers.get("x-revalidate-secret") ?? req.nextUrl.searchParams.get("secret");
  if (!secret || given !== secret) {
    return NextResponse.json({ ok: false, error: "unauthorized" }, { status: 401 });
  }

  let tags: string[] = ["cms"];
  try {
    const body = (await req.json()) as { tags?: unknown };
    if (Array.isArray(body.tags) && body.tags.length) tags = body.tags.filter((t): t is string => typeof t === "string" && t.length > 0);
  } catch {
    /* body kosong → semua */
  }

  for (const tag of tags) revalidateTag(tag, "max");

  return NextResponse.json({ ok: true, revalidated: tags, at: new Date().toISOString() });
}
