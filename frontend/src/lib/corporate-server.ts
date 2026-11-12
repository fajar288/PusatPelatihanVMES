// Helper server-only Corporate Portal (memakai token sesi di cookie httpOnly). Jangan diimpor dari client component.
import { notFound, redirect } from "next/navigation";
import { api, ApiRequestError, requireUser } from "./auth";
import { type CorporateSummary, orgPath } from "./corporate";

/** Panggil API corporate; 404/403 organisasi → halaman 404 (tidak membocorkan keberadaan organisasi lain). */
export async function corporateApi<T>(path: string, next: string): Promise<T> {
  const { token } = await requireUser(next);
  try {
    return await api<T>(path, { token });
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    if (e instanceof ApiRequestError && e.error.status === 401) redirect(`/login?next=${encodeURIComponent(next)}`);
    throw e;
  }
}

export const getSummary = (org: string) => corporateApi<{ data: CorporateSummary }>(orgPath(org), `/corporate/${org}`).then((r) => r.data);
