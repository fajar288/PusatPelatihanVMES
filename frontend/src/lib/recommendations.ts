/** Rekomendasi training (dibuat AI, aturan/statistik): satu program beserta alasan yang bisa ditelusuri. */
export type RecommendationItem = {
  slug: string;
  title: string;
  category: string | null;
  rank: number | null;
  reasons: string[];
  next: { code: string; dateLabel: string } | null;
};

export type Recommendations = {
  insightId: number;
  aiGenerated: true;
  computedAt: string | null;
  items: RecommendationItem[];
  groups: { position: string; employees: number; items: RecommendationItem[] }[] | null;
  myFeedback: boolean | null;
};

export type RecommendationResponse = {
  status: { available: boolean; label: string; reason: string | null };
  recommendations: Recommendations | null;
};

/** Tautan lewat pencatat klik (/rekomendasi/klik), lalu diteruskan ke halaman tujuan internal. */
export function clickHref(insightId: number, slug: string, to: string): string {
  return `/rekomendasi/klik?${new URLSearchParams({ i: String(insightId), p: slug, to }).toString()}`;
}
