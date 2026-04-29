$j1 = Start-Job -ScriptBlock { Set-Location 'C:\xampp\htdocs\ExpMgWEB'; php scripts/stress/multi_worker_lock_test.php worker1 lock:test:expense-collision 8 }
$j2 = Start-Job -ScriptBlock { Set-Location 'C:\xampp\htdocs\ExpMgWEB'; php scripts/stress/multi_worker_lock_test.php worker2 lock:test:expense-collision 8 }
$j3 = Start-Job -ScriptBlock { Set-Location 'C:\xampp\htdocs\ExpMgWEB'; php scripts/stress/multi_worker_lock_test.php worker3 lock:test:expense-collision 8 }
Wait-Job -Job $j1,$j2,$j3 | Out-Null
$o = Receive-Job -Job $j1,$j2,$j3
$o
Remove-Job -Job $j1,$j2,$j3
