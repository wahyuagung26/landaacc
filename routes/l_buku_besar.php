<?php
$app->get('/acc/l_buku_besar/laporan', function ($request, $response) {
    $subDomain = str_replace('api/', '', site_url());
    $data['img'] = imgLaporan();
    $params = $request->getParams();
    $sql = $this->db;
    $arr = [];
    /**
     * tanggal awal
     */
    $tanggal_awal = new DateTime($params['startDate']);
    $tanggal_awal->setTimezone(new DateTimeZone('Asia/Jakarta'));
    /**
     * tanggal akhir
     */
    $tanggal_akhir = new DateTime($params['endDate']);
    $tanggal_akhir->setTimezone(new DateTimeZone('Asia/Jakarta'));
    /**
     * Format Tanggal
     */
    $tanggal_start = $tanggal_awal->format("Y-m-d");
    $tanggal_end = $tanggal_akhir->format("Y-m-d");
    /**
     * Siapkan sub header laporan
     */
    $data['tanggal'] = date("d M Y", strtotime($tanggal_start)) . ' s/d ' . date("d M Y", strtotime($tanggal_end));
    $data['disiapkan'] = date("d-m-Y, H:i");
    $data['lokasi'] = (isset($params['nama_lokasi']) && !empty($params['nama_lokasi'])) ? $params['nama_lokasi'] : "Semua";
    /*
     * Siapkan parameter lokasi
     */
    if (isset($params['m_lokasi_id'])) {
        $lokasiId = getChildId("acc_m_lokasi", $params['m_lokasi_id']);
        /*
         * jika lokasi punya child
         */
        if (!empty($lokasiId)) {
            $lokasiId[] = $params['m_lokasi_id'];
            $lokasiId = implode(",", $lokasiId);
        }
        /*
         * jika lokasi tidak punya child
         */else {
            $lokasiId = $params['m_lokasi_id'];
        }
    }
    /**
     * Proses laporan
     */
    if (isset($params['m_lokasi_id'])) {
        /**
         * Ambil data akun
         */
        $sql->select("acc_m_akun.*, klasifikasi.nama as klasifikasi")
            ->from("acc_m_akun")
            ->leftJoin("acc_m_akun as klasifikasi", "klasifikasi.id = acc_m_akun.parent_id")
            ->andWhere("acc_m_akun.is_deleted", "=", 0)
            ->orderBy("acc_m_akun.kode ASC");

        if (!empty($params['m_akun_group_id'])) {
            $sql->where("acc_m_akun.m_akun_group_id", "=", $params['m_akun_group_id']);
        }

        if (isset($params['m_akun_id']) && !empty($params['m_akun_id'])) {
            $getakun = $sql->customWhere("acc_m_akun.id = '" . $params['m_akun_id'] . "'", "AND")->findAll();
        } else {
            $getakun = $sql->where("acc_m_akun.is_tipe", "=", 1)->findAll();
        }

        /**
         * Ambil akun laba rugi
         */
        $labarugi = getPemetaanAkun("Laba Rugi Berjalan");
        $akunLabaRugi = isset($labarugi[0]) ? $labarugi[0] : 0;
        $saldoLabaRugi = getLabaRugiNominal($tanggal_start, $tanggal_end, null);
        $totalLabaRugi = $saldoLabaRugi["total"];
        /**
         * Proses laporan
         */
        $index = 0;
        $arr = [];
        foreach ($getakun as $keys => $vals) {
            if ($vals->is_tipe == 1) {
                /**
                 * Ambil id akun turunan klasifikasi di parameter
                 */
                $childId = getChildId("acc_m_akun", $vals->id);
                if (!empty($childId)) {
                    $saldo_sekarang = $total_debit = $total_kredit = 0;
                    $getchild = $sql->select("acc_m_akun.*, klasifikasi.nama as klasifikasi")
                        ->from("acc_m_akun")
                        ->leftJoin("acc_m_akun as klasifikasi", "klasifikasi.id = acc_m_akun.parent_id")
                        ->customWhere("acc_m_akun.id in (" . implode(",", $childId) . ")")
                        ->andWhere("acc_m_akun.is_deleted", "=", 0)
                        ->andWhere("acc_m_akun.is_tipe", "=", 0)
                        ->orderBy("acc_m_akun.kode ASC")
                        ->findAll();
                    foreach ($getchild as $key => $val) {
                        /**
                         * Ambil Saldo awal akun
                         */
                        $sql->select("SUM(debit) as debit, SUM(kredit) as kredit")->from("acc_trans_detail");
                        if (isset($params['m_lokasi_id']) && !empty($params['m_lokasi_id'])) {
                            $sql->customWhere("m_lokasi_id in (" . $lokasiId . ")");
                        }
                        $sql->where('m_akun_id', '=', $val->id)->andWhere('date(tanggal)', '<', $tanggal_start);
                        $getsaldoawal = $sql->find();
                        $arr[$val->id]['saldo_awal'] = $getsaldoawal->debit - $getsaldoawal->kredit;
                        $arr[$val->id]['debit_awal'] = $getsaldoawal->debit;
                        $arr[$val->id]['kredit_awal'] = $getsaldoawal->kredit;
                        $arr[$val->id]['kode_akun'] = $val->kode;
                        $arr[$val->id]['nama_akun'] = $val->nama;
                        $arr[$val->id]['akun'] = $val->kode . ' - ' . $val->nama;
                        $arr[$val->id]['akun_id'] = $val->id;
                        $arr[$val->id]['klasifikasi'] = $val->klasifikasi;
                        if (isset($getsaldoawal->id)) {
                            if ($vals->id == $akunLabaRugi) {
                                $arr[$val->id]['saldo_awal'] = $totalLabaRugi;
                                $arr[$val->id]['debit_awal'] = ($totalLabaRugi >= 0) ? $totalLabaRugi : 0;
                                $arr[$val->id]['kredit_awal'] = ($totalLabaRugi < 0) ? $totalLabaRugi : 0;
                            } else {
                                $arr[$val->id]['saldo_awal'] = $getsaldoawal->debit - $getsaldoawal->kredit;
                                $arr[$val->id]['debit_awal'] = $getsaldoawal->debit;
                                $arr[$val->id]['kredit_awal'] = $getsaldoawal->kredit;
                                /**
                                 * Detail saldo awal
                                 */
                                $saldo_sekarang += $getsaldoawal->debit - $getsaldoawal->kredit;
                                $arr[$val->id]['detail'][0]['tanggal'] = $getsaldoawal->tanggal;
                                $arr[$val->id]['detail'][0]['kode'] = $getsaldoawal->kode;
                                $arr[$val->id]['detail'][0]['keterangan'] = $getsaldoawal->keterangan;
                                $arr[$val->id]['detail'][0]['debit'] = $getsaldoawal->debit;
                                $arr[$val->id]['detail'][0]['kredit'] = $getsaldoawal->kredit;
                                $arr[$val->id]['detail'][0]['saldo_sekarang'] = $saldo_sekarang;
                            }
                            $total_debit += $getsaldoawal->debit;
                            $total_kredit += $getsaldoawal->kredit;
                        }
                        /**
                         * Ambil detail transaksi
                         */
                        $gettransdetail = $sql->select("acc_trans_detail.*, "
                        . "acc_m_kontak.nama as nama_kontak")
                            ->from("acc_trans_detail")
                            ->leftJoin("acc_m_kontak", "acc_m_kontak.id = acc_trans_detail.m_kontak_id");
                        if (isset($params['m_lokasi_id']) && !empty($params['m_lokasi_id'])) {
                            $sql->customWhere("m_lokasi_id in (" . $lokasiId . ")");
                        }
                        $sql->where('m_akun_id', '=', $val->id)
                            ->andWhere('date(tanggal)', '>=', $tanggal_start)
                            ->andWhere('date(tanggal)', '<=', $tanggal_end)
                            ->orderBy('tanggal');
                        $detail = $sql->findAll();
                        $saldo_sekarang = $arr[$val->id]['saldo_awal'];
                        $total_debit = $arr[$val->id]['debit_awal'];
                        $total_kredit = $arr[$val->id]['kredit_awal'];
                        /**
                         * Siapkan array laporan untuk semua akun
                         */
                        foreach ($detail as $index2 => $val2) {
                            $saldo_sekarang += $val2->debit - $val2->kredit;
                            $arr[$val->id]['detail'][$index2 + 1]['tanggal'] = $val2->tanggal;
                            $arr[$val->id]['detail'][$index2 + 1]['kode'] = $val2->kode;
                            $arr[$val->id]['detail'][$index2 + 1]['nama_kontak'] = $val2->nama_kontak;
                            $arr[$val->id]['detail'][$index2 + 1]['keterangan'] = $val2->keterangan;
                            $arr[$val->id]['detail'][$index2 + 1]['debit'] = $val2->debit;
                            $arr[$val->id]['detail'][$index2 + 1]['kredit'] = $val2->kredit;
                            $arr[$val->id]['detail'][$index2 + 1]['saldo_sekarang'] = $saldo_sekarang;
                            $total_debit += $val2->debit;
                            $total_kredit += $val2->kredit;
                        }
                        $arr[$val->id]['total_debit'] = $total_debit;
                        $arr[$val->id]['total_kredit'] = $total_kredit;
                        $arr[$val->id]['total_saldo'] = $total_debit - $total_kredit;
                        $index += 1;
                    }
                    /**
                     * Hapus akun tidak ada transaksi
                     */
                    foreach ($arr as $key => $value) {
                        if ($value['total_saldo'] > 0 || $value['total_saldo'] < 0) {

                        } else {
                            unset($arr[$key]);
                        }
                    }
                }
            } else {
                /**
                 * Ambil Saldo Awal Akun
                 */
                $saldo_sekarang = $total_debit = $total_kredit = 0;
                $sql->select("id, SUM(debit) as debit, SUM(kredit) as kredit")
                    ->from("acc_trans_detail");
                if (isset($params['m_lokasi_id']) && !empty($params['m_lokasi_id'])) {
                    $sql->customWhere("m_lokasi_id in (" . $lokasiId . ")");
                }
                $sql->where('m_akun_id', '=', $vals->id)
                    ->andWhere('date(tanggal)', '<', $tanggal_start);
                $getsaldoawal = $sql->find();
                $arr[0]['saldo_awal'] = 0;
                $arr[0]['debit_awal'] = 0;
                $arr[0]['kredit_awal'] = 0;
                $arr[0]['kode_akun'] = $vals->kode;
                $arr[0]['nama_akun'] = $vals->nama;
                $arr[0]['akun'] = $vals->kode . ' - ' . $vals->nama;
                $arr[0]['klasifikasi'] = $vals->klasifikasi;
                if (isset($getsaldoawal->id)) {
                    if ($vals->id == $akunLabaRugi) {
                        $arr[0]['saldo_awal'] = $totalLabaRugi;
                        $arr[0]['debit_awal'] = ($totalLabaRugi >= 0) ? $totalLabaRugi : 0;
                        $arr[0]['kredit_awal'] = ($totalLabaRugi < 0) ? $totalLabaRugi : 0;
                    } else {
                        $arr[0]['saldo_awal'] = $getsaldoawal->debit - $getsaldoawal->kredit;
                        $arr[0]['debit_awal'] = $getsaldoawal->debit;
                        $arr[0]['kredit_awal'] = $getsaldoawal->kredit;
                        /**
                         * Detail saldo awal
                         */
                        $saldo_sekarang += $getsaldoawal->debit - $getsaldoawal->kredit;
                        // $arr[0]['detail'][0]['tanggal'] = $getsaldoawal->tanggal;
                        // $arr[0]['detail'][0]['kode'] = $getsaldoawal->kode;
                        // $arr[0]['detail'][0]['keterangan'] = $getsaldoawal->keterangan;
                        // $arr[0]['detail'][0]['debit'] = $getsaldoawal->debit;
                        // $arr[0]['detail'][0]['kredit'] = $getsaldoawal->kredit;
                        // $arr[0]['detail'][0]['saldo_sekarang'] = $saldo_sekarang;
                    }
                    // $total_debit += $getsaldoawal->debit;
                    // $total_kredit += $getsaldoawal->kredit;
                }
                /**
                 * Ambil detail transaksi
                 */
                $sql->select("acc_trans_detail.*, "
                    . "acc_m_kontak.nama as nama_kontak")
                    ->from("acc_trans_detail")
                    ->leftJoin("acc_m_kontak", "acc_m_kontak.id = acc_trans_detail.m_kontak_id");
                if (isset($params['m_lokasi_id']) && !empty($params['m_lokasi_id'])) {
                    $sql->customWhere("m_lokasi_id in (" . $lokasiId . ")");
                }
                $sql->where('m_akun_id', '=', $vals->id)
                    ->andWhere('date(tanggal)', '>=', $tanggal_start)
                    ->andWhere('date(tanggal)', '<=', $tanggal_end)
                    ->orderBy('tanggal');
                $detail = $sql->findAll();
                foreach ($detail as $key2 => $val2) {
                    $saldo_sekarang += $val2->debit - $val2->kredit;
                    $arr[0]['detail'][$key2 + 1]['tanggal'] = $val2->tanggal;
                    $arr[0]['detail'][$key2 + 1]['kode'] = $val2->kode;
                    $arr[0]['detail'][$key2 + 1]['nama_kontak'] = $val2->nama_kontak;
                    $arr[0]['detail'][$key2 + 1]['keterangan'] = $val2->keterangan;
                    $arr[0]['detail'][$key2 + 1]['debit'] = $val2->debit;
                    $arr[0]['detail'][$key2 + 1]['kredit'] = $val2->kredit;
                    $arr[0]['detail'][$key2 + 1]['saldo_sekarang'] = $saldo_sekarang;
                    $total_debit += $val2->debit;
                    $total_kredit += $val2->kredit;
                }
                $arr[0]['total_debit'] = $total_debit;
                $arr[0]['total_kredit'] = $total_kredit;
                $arr[0]['total_saldo'] = $arr[0]['saldo_awal'] + $total_debit - $total_kredit;
            }
        }
        if (isset($params['export']) && $params['export'] == 1) {

            // print_die($arr);

            $view = twigViewPath();
            $content = $view->fetch('laporan/bukuBesar.html', [
                "data" => $data,
                "detail" => $arr,
                "css" => modulUrl() . '/assets/css/style.css',
            ]);
            header("Content-type: application/vnd.ms-excel");
            header("Content-Disposition: attachment;Filename=laporan-buku-besar.xls");
            echo $content;
        } else if (isset($params['print']) && $params['print'] == 1) {
            $view = twigViewPath();
            $content = $view->fetch('laporan/bukuBesar.html', [
                "data" => $data,
                "detail" => $arr,
                "css" => modulUrl() . '/assets/css/style.css',
            ]);
            echo $content;
            echo '<script type="text/javascript">window.print();setTimeout(function () { window.close(); }, 500);</script>';
        } else {
            return successResponse($response, ["data" => $data, "detail" => $arr]);
        }
    } else {
        return unprocessResponse($response, ["Silahkan pilih lokasi dan akun terlebih dahulu"]);
    }
});
