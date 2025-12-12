export function getFilename(fullPath:string) {
  // Find the last index of either '\' or '/'
  const lastBackslashIndex = fullPath.lastIndexOf('\\');
  const lastSlashIndex = fullPath.lastIndexOf('/');
  
  // Choose the maximum index to handle both Windows and Unix paths correctly
  const lastIndex = Math.max(lastBackslashIndex, lastSlashIndex);
  
  // Extract the file name using substring
  const fileName = fullPath.substring(lastIndex + 1);
  
  return fileName;
}

// Example usage:
// const relativePathUnix = 'folder/subfolder/file.jpg';
// const relativePathWin = 'folder\\subfolder\\file.jpg';
// const filename1 = getFilename(relativePathUnix); // 'file.jpg'
// const filename2 = getFilename(relativePathWin); // 'file.jpg'

// console.log(filename1);
// console.log(filename2);
