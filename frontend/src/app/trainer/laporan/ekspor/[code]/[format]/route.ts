import { NextResponse } from "next/server";
import { proxyFile } from "@/lib/file-proxy";

/** /trainer/laporan/ekspor/[code]/[format]: unduh rincian peserta satu kelas (CSV/XLSX) dari API trainer. */
export async function GET(_request: Request, { params }: RouteContext<"/trainer/laporan/ekspor/[code]/[format]">) {
  const { code, format } = await params;
  if (format !== "csv" && format !== "xlsx") return new NextResponse("Format tidak dikenal.", { status: 404 });
  return proxyFile(`/trainer/reports/${encodeURIComponent(code)}/export.${format}`, "/trainer/laporan");
}
