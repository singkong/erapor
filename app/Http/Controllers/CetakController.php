<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PDF;
/*
output(): Outputs the PDF as a string.
save($filename): Save the PDF to a file
download($filename): Make the PDF downloadable by the user.
stream($filename): Return a response with the PDF to show in the browser.
*/
use App\Anggota_rombel;
use App\Rencana_ukk;
use App\Paket_ukk;
use App\Guru;
use App\Sekolah;
use App\Nilai_ukk;
use CustomHelper;
use App\Rombongan_belajar;
use App\Rencana_penilaian;
use App\Rapor_pts;
class CetakController extends Controller
{
	public function __construct()
    {
        $this->middleware('auth');
    }
    public function generate_pdf(){
		$data = [
			'foo' => 'bar'
		];
		$pdf = PDF::loadView('cetak.document', $data);
		return $pdf->stream('document.pdf');
	}
	public function sertifikat($anggota_rombel_id, $rencana_ukk_id){
		$user = auth()->user();
        $anggota_rombel = Anggota_rombel::with('siswa')->find($anggota_rombel_id);
		$callback = function($query) use ($anggota_rombel_id){
			$query->where('anggota_rombel_id', $anggota_rombel_id);
		};
		$rencana_ukk = Rencana_ukk::with('guru_internal')->with(['guru_eksternal' => function($query){
			$query->with('dudi');
		}])->with(['nilai_ukk' => $callback])->find($rencana_ukk_id);
		$count_penilaian_ukk = Nilai_ukk::where('peserta_didik_id', $anggota_rombel->peserta_didik_id)->count();
		$data['siswa'] = $anggota_rombel;
		$data['sekolah_id'] = $user->sekolah_id;
		$data['rencana_ukk'] = $rencana_ukk;
		$data['count_penilaian_ukk'] = $count_penilaian_ukk;
		$data['paket'] = Paket_ukk::with('jurusan')->with('unit_ukk')->find($rencana_ukk->paket_ukk_id);
		$data['asesor'] = Guru::with('dudi')->find($rencana_ukk->eksternal);
		$data['sekolah'] = Sekolah::with('guru')->find($user->sekolah_id);
		$pdf = PDF::loadView('cetak.sertifikat1', $data);
		$pdf->getMpdf()->AddPage('P');
		$rapor_cover= view('cetak.sertifikat2', $data);
		$pdf->getMpdf()->WriteHTML($rapor_cover);
		$general_title = strtoupper($anggota_rombel->siswa->nama);
		return $pdf->stream($general_title.'-SERTIFIKAT.pdf');  
	}
	public function rapor_pts($rombongan_belajar_id){
		$callback = function($query){
			$query->with('nilai');
		};
		$rombongan_belajar = Rombongan_belajar::with('wali')->with(['siswa' => function($query){
			$query->with(['anggota_rombel' => function($q){
				$q->with('catatan_wali');
			}]);
			$query->with(['sekolah' => function($q){
				$q->with('guru');
			}]);
			$query->orderBy('nama');
		}])->with(['pembelajaran' => function($query) use ($callback){
			$query->with('kelompok')->orderBy('kelompok_id', 'asc')->orderBy('no_urut', 'asc');
			$query->whereHas('rapor_pts', $callback)->with(['rapor_pts'=> $callback]);
		}])->with('semester')->with('jurusan')->with('kurikulum')->find($rombongan_belajar_id);
		if (strpos($rombongan_belajar->kurikulum->nama_kurikulum, 'REV') !== false) {
			$kur = 2017;
		} elseif (strpos($rombongan_belajar->kurikulum->nama_kurikulum, '2013') !== false) {
			$kur = 2013;
		} else {
			$kur = 2006;
		}
		$pdf = PDF::loadView('cetak.blank');
		$pdf->getMpdf()->defaultfooterfontsize=7;
		$pdf->getMpdf()->defaultfooterline=0;
		$data['rombongan_belajar'] = $rombongan_belajar;
		$tanggal_rapor = CustomHelper::get_setting('tanggal_rapor');
		$tanggal_rapor = date('Y-m-d', strtotime($tanggal_rapor));
		$data['tanggal_rapor'] = $tanggal_rapor;
		foreach($rombongan_belajar->siswa as $siswa){
			$pdf->getMpdf()->SetFooter(strtoupper($siswa->nama).' - '.$rombongan_belajar->nama.'|{PAGENO}|Dicetak dari '.config('site.app_name').' v.'.CustomHelper::get_setting('app_version'));
			$data['siswa'] = $siswa;
			$data['sekolah'] = $siswa->sekolah;
			$data['semester'] = $rombongan_belajar->semester;
			$rapor_cover = view('cetak.pts.cover', $data);
			$pdf->getMpdf()->WriteHTML($rapor_cover);
			foreach($rombongan_belajar->pembelajaran as $pembelajaran){
				$all_nilai[$pembelajaran->kelompok->nama_kelompok][$siswa->peserta_didik_id][] = array(
					'nama_mata_pelajaran'	=> $pembelajaran->nama_mata_pelajaran,
					'kkm'	=> CustomHelper::get_kkm($pembelajaran->kelompok_id, $pembelajaran->kkm),
					'angka'	=> number_format($pembelajaran->rapor_pts->nilai()->where('anggota_rombel_id', $siswa->anggota_rombel->anggota_rombel_id)->avg('nilai'),0),
					'terbilang' => CustomHelper::terbilang(number_format($pembelajaran->rapor_pts->nilai()->where('anggota_rombel_id', $siswa->anggota_rombel->anggota_rombel_id)->avg('nilai'),0)),
				);
			}
			$data['all_nilai'] = $all_nilai;
			$pdf->getMpdf()->AddPage('P','','','','',5,5,5,5,5,5,'', 'Legal');
			$rapor_nilai = view('cetak.pts.rapor_nilai_'.$kur, $data);
			$pdf->getMpdf()->WriteHTML($rapor_nilai);
			$pdf->getMpdf()->AddPage('P','','1','','',10,10,10,10,5,5,'', 'Legal');
		}
		return $pdf->stream('rapor_pts.pdf');  
	}
	public function rapor_top($query, $id){
		if($query){
			$get_siswa = Anggota_rombel::with(['siswa' => function($query){
				$query->with('agama')->with(['get_kecamatan' => function($query){
					$query->with('get_kabupaten');
				}]);
				$query->with('pekerjaan_ayah');
				$query->with('pekerjaan_ibu');
				$query->with('pekerjaan_wali');
			}])->with(['rombongan_belajar' => function($query){
				$query->with(['pembelajaran' => function($query){
					$query->with('kelompok');
					$query->with('nilai_akhir_pengetahuan');
					$query->with('nilai_akhir_keterampilan');
					$query->whereNotNull('kelompok_id');
					$query->orderBy('kelompok_id', 'asc');
					$query->orderBy('no_urut', 'asc');
				}]);
				$query->with('semester');
				$query->with('jurusan');
				$query->with('kurikulum');
			}])->with(['sekolah' => function($q){
				$q->with('guru');
			}])->find($id);
			$params = array(
				'get_siswa'	=> $get_siswa,
			);
			$pdf = PDF::loadView('cetak.blank', $params, [], [
				'format' => 'A4',
				'margin_left' => 15,
				'margin_right' => 15,
				'margin_top' => 15,
				'margin_bottom' => 15,
				'margin_header' => 5,
				'margin_footer' => 5,
			]);
			$pdf->getMpdf()->defaultfooterfontsize=7;
			$pdf->getMpdf()->defaultfooterline=0;
			$general_title = strtoupper($get_siswa->siswa->nama).' - '.$get_siswa->rombongan_belajar->nama;
			$pdf->getMpdf()->SetFooter($general_title.'|{PAGENO}|Dicetak dari '.config('site.app_name').' v.'.CustomHelper::get_setting('app_version'));
			$rapor_top = view('cetak.rapor_top', $params);
			$identitas_sekolah = view('cetak.identitas_sekolah', $params);
			$identitas_peserta_didik = view('cetak.identitas_peserta_didik', $params);
			$pdf->getMpdf()->WriteHTML($rapor_top);
			$pdf->getMpdf()->WriteHTML($identitas_sekolah);
			$pdf->getMpdf()->WriteHTML('<pagebreak />');
			$pdf->getMpdf()->WriteHTML($identitas_peserta_didik);
			return $pdf->stream($general_title.'-IDENTITAS.pdf');
		} else {
			$get_siswa = Anggota_rombel::with('siswa')->with('rombongan_belajar')->where('rombongan_belajar_id', $id)->order()->get();
		}
		$tanggal_rapor = CustomHelper::get_setting('tanggal_rapor');
		$tanggal_rapor = date('Y-m-d', strtotime($tanggal_rapor));
		$params = array(
			'get_siswa'	=> $get_siswa,
			'tanggal_rapor'	=> $tanggal_rapor,
		);
	}
	public function rapor_nilai($query, $id){
		if($query){
			$user = auth()->user();
			$semester = CustomHelper::get_ta();
			$cari_tingkat_akhir = Rombongan_belajar::where('sekolah_id', $user->sekolah_id)->where('semester_id', $semester->semester_id)->where('tingkat', 13)->first();
			$get_siswa = Anggota_rombel::with(['siswa' => function($query){
				$query->with('agama')->with(['get_kecamatan' => function($query){
					$query->with('get_kabupaten');
				}]);
				$query->with('pekerjaan_ayah');
				$query->with('pekerjaan_ibu');
				$query->with('pekerjaan_wali');
			}])->with(['rombongan_belajar' => function($query) use ($id){
				$query->where('jenis_rombel', 1);
				$query->with(['pembelajaran' => function($query) use ($id){
					$callback = function($query) use ($id){
						$query->where('anggota_rombel_id', $id);
					};
					$query->with('kelompok');
					$query->whereHas('nilai_akhir_pengetahuan', $callback);
					$query->with(['nilai_akhir_pengetahuan' => $callback]);
					$query->whereHas('nilai_akhir_keterampilan', $callback);
					$query->with(['nilai_akhir_keterampilan' => $callback]);
					$query->whereNotNull('kelompok_id');
					$query->orderBy('kelompok_id', 'asc');
					$query->orderBy('no_urut', 'asc');
				}]);
				$query->with('semester');
				$query->with('jurusan');
				$query->with('kurikulum');
				$query->with('wali');
			}])->with(['sekolah' => function($q){
				$q->with('guru');
			}])->with(['catatan_ppk' => function($query){
				$query->with(['nilai_karakter' => function($query){
					$query->with('sikap');
				}]);
			}])->with('kenaikan')->with(['all_nilai_ekskul' => function($query){
				$query->with('ekstrakurikuler');
			}])->with('kehadiran')->with('all_prakerin')->with('catatan_wali')->find($id);
			$tanggal_rapor = CustomHelper::get_setting('tanggal_rapor');
			$tanggal_rapor = date('Y-m-d', strtotime($tanggal_rapor));
			$params = array(
				'get_siswa'	=> $get_siswa,
				'tanggal_rapor'	=> $tanggal_rapor,
				'cari_tingkat_akhir'	=> $cari_tingkat_akhir,
			);
			$pdf = PDF::loadView('cetak.blank', $params, [], [
				'format' => 'A4',
				'margin_left' => 15,
				'margin_right' => 15,
				'margin_top' => 15,
				'margin_bottom' => 15,
				'margin_header' => 5,
				'margin_footer' => 5,
			]);
			$pdf->getMpdf()->defaultfooterfontsize=7;
			$pdf->getMpdf()->defaultfooterline=0;
			$general_title = strtoupper($get_siswa->siswa->nama).' - '.$get_siswa->rombongan_belajar->nama;
			$pdf->getMpdf()->SetFooter($general_title.'|{PAGENO}|Dicetak dari '.config('site.app_name').' v.'.CustomHelper::get_setting('app_version'));
			$rapor_nilai = view('cetak.rapor_nilai', $params);
			$rapor_catatan = view('cetak.rapor_catatan', $params);
			$rapor_karakter = view('cetak.rapor_karakter', $params);
			$pdf->getMpdf()->WriteHTML($rapor_nilai);
			$pdf->getMpdf()->WriteHTML('<pagebreak />');
			$pdf->getMpdf()->WriteHTML($rapor_catatan);
			$pdf->getMpdf()->WriteHTML('<pagebreak />');
			$pdf->getMpdf()->WriteHTML($rapor_karakter);
			return $pdf->stream($general_title.'-NILAI.pdf');
		} else {
			//$id = rombongan_belajar_id
		}
	}
	public function rapor_pendukung($query, $id){
		if($query){
			$get_siswa = Anggota_rombel::with('siswa')->with('sekolah')->with('prestasi')->find($id);
			$params = array(
				'get_siswa'	=> $get_siswa,
			);
			$pdf = PDF::loadView('cetak.blank', $params, [], [
				'format' => 'A4',
				'margin_left' => 15,
				'margin_right' => 15,
				'margin_top' => 15,
				'margin_bottom' => 15,
				'margin_header' => 5,
				'margin_footer' => 5,
			]);
			$pdf->getMpdf()->defaultfooterfontsize=7;
			$pdf->getMpdf()->defaultfooterline=0;
			$general_title = strtoupper($get_siswa->siswa->nama).' - '.$get_siswa->rombongan_belajar->nama;
			$pdf->getMpdf()->SetFooter($general_title.'| |Dicetak dari eRaporSMK v.'.CustomHelper::get_setting('app_version'));
			$rapor_pendukung = view('cetak.rapor_pendukung', $params);
			$pdf->getMpdf()->WriteHTML($rapor_pendukung);
			return $pdf->stream($general_title.'-LAMPIRAN.pdf');
		} else {
			//$id = rombongan_belajar_id
		}
		$pdf = PDF::loadView('cetak.perbaikan');
		return $pdf->stream('document.pdf');
	}
}
